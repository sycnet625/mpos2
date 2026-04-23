<?php
// ARCHIVO: business_closure_report.php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';
require_once 'config_loader.php';

// --- 0. AUTO-MIGRACIÓN: CREAR TABLA DE REPORTES SI NO EXISTE ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reportes_cierre (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_sucursal INT NOT NULL,
        fecha_inicio DATE NOT NULL,
        fecha_fin DATE NOT NULL,
        venta_total DECIMAL(10,2) DEFAULT 0,
        ganancia_neta DECIMAL(10,2) DEFAULT 0,
        datos_json TEXT, -- Snapshot de los datos clave
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) { /* Silencio si ya existe */ }

// 1. CARGAR CONFIGURACIÓN
$id_sucursal = intval($config['id_sucursal']);
$id_almacen = intval($config['id_almacen']);
$week_start_day = intval($config['semana_inicio_dia'] ?? 1);
$pct_reserva = floatval($config['reserva_limpieza_pct'] ?? 10);

// 2. MANEJO DE ACCIONES (GUARDAR / BORRAR)
$mensaje_accion = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'guardar') {
        try {
            // Guardamos un snapshot básico
            $stmt = $pdo->prepare("INSERT INTO reportes_cierre (id_sucursal, fecha_inicio, fecha_fin, venta_total, ganancia_neta, datos_json) VALUES (?, ?, ?, ?, ?, ?)");
            $snapshot = json_encode(['nota' => 'Cierre Generado Manualmente']);
            $stmt->execute([$id_sucursal, $_POST['f_inicio'], $_POST['f_fin'], $_POST['v_total'], $_POST['g_neta'], $snapshot]);
            $mensaje_accion = "<div class='alert alert-success py-2'>Reporte guardado correctamente en el historial.</div>";
        } catch (Exception $e) {
            $mensaje_accion = "<div class='alert alert-danger py-2'>Error al guardar: ".$e->getMessage()."</div>";
        }
    }
    elseif (isset($_POST['action']) && $_POST['action'] === 'borrar') {
        try {
            $stmt = $pdo->prepare("DELETE FROM reportes_cierre WHERE id = ? AND id_sucursal = ?");
            $stmt->execute([$_POST['id_reporte'], $id_sucursal]);
            $mensaje_accion = "<div class='alert alert-warning py-2'>Reporte eliminado del historial.</div>";
        } catch (Exception $e) {
            $mensaje_accion = "<div class='alert alert-danger py-2'>Error al borrar: ".$e->getMessage()."</div>";
        }
    }
}

// 3. MANEJO DE FECHAS
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

if (isset($_GET['periodo'])) {
    $p = $_GET['periodo'];
    $today = new DateTime();
    if ($p === 'week') {
        $current_day_of_week = $today->format('w');
        $diff = ($current_day_of_week - $week_start_day + 7) % 7;
        $start = clone $today;
        $start->modify("-$diff days");
        $fecha_inicio = $start->format('Y-m-d');
        $fecha_fin = $today->format('Y-m-d');
    } elseif ($p === 'month') {
        $fecha_inicio = $today->format('Y-m-01');
        $fecha_fin = $today->format('Y-m-t');
    } elseif ($p === 'year') {
        $fecha_inicio = $today->format('Y-01-01');
        $fecha_fin = $today->format('Y-12-31');
    } elseif ($p === '30d') {
        $start = clone $today;
        $start->modify("-30 days");
        $fecha_inicio = $start->format('Y-m-d');
        $fecha_fin = $today->format('Y-m-d');
    } elseif ($p === '180d') {
        $start = clone $today;
        $start->modify("-180 days");
        $fecha_inicio = $start->format('Y-m-d');
        $fecha_fin = $today->format('Y-m-d');
    }
}

// CALCULO DE FECHAS PARA BOTONES DE NAVEGACION
$prev7_ini = date('Y-m-d', strtotime($fecha_inicio . ' - 7 days'));
$prev7_fin = date('Y-m-d', strtotime($fecha_fin . ' - 7 days'));
$next7_ini = date('Y-m-d', strtotime($fecha_inicio . ' + 7 days'));
$next7_fin = date('Y-m-d', strtotime($fecha_fin . ' + 7 days'));

$prev30_ini = date('Y-m-d', strtotime($fecha_inicio . ' - 30 days'));
$prev30_fin = date('Y-m-d', strtotime($fecha_fin . ' - 30 days'));
$next30_ini = date('Y-m-d', strtotime($fecha_inicio . ' + 30 days'));
$next30_fin = date('Y-m-d', strtotime($fecha_fin . ' + 30 days'));

function business_report_ticket_session_id(array $ticket): int {
    $sessionId = intval($ticket['id_sesion_caja'] ?? 0);
    if ($sessionId > 0) {
        return $sessionId;
    }
    return intval($ticket['id_caja'] ?? 0);
}

// 4. OBTENER DATOS PRINCIPALES
try {
    // DESGLOSE DIARIO
    $sqlDaily = "SELECT 
                    dates.dia,
                    COALESCE((SELECT SUM(total) FROM ventas_cabecera WHERE id_sucursal = ? AND DATE(fecha) = dates.dia), 0) as total_venta,
                    COALESCE((SELECT COUNT(id) FROM ventas_cabecera WHERE id_sucursal = ? AND DATE(fecha) = dates.dia), 0) as num_transacciones,
                    COALESCE((SELECT SUM(vd.cantidad * p.costo) 
                              FROM ventas_detalle vd 
                              JOIN ventas_cabecera vc ON vd.id_venta_cabecera = vc.id 
                              JOIN productos p ON vd.id_producto = p.codigo
                              WHERE vc.id_sucursal = ? AND DATE(vc.fecha) = dates.dia), 0) as total_costo,
                    COALESCE((SELECT SUM(monto) FROM gastos_historial WHERE id_sucursal = ? AND DATE(fecha) = dates.dia), 0) as total_gasto,
                    COALESCE((SELECT SUM(vp.monto) 
                              FROM ventas_pagos vp 
                              JOIN ventas_cabecera vc ON vp.id_venta_cabecera = vc.id 
                              WHERE vc.id_sucursal = ? AND DATE(vc.fecha) = dates.dia AND vp.metodo_pago = 'Transferencia'), 0) as total_transferencia
                 FROM (
                    SELECT DISTINCT DATE(fecha) as dia FROM ventas_cabecera WHERE id_sucursal = ? AND DATE(fecha) BETWEEN ? AND ?
                    UNION
                    SELECT DISTINCT DATE(fecha) as dia FROM gastos_historial WHERE id_sucursal = ? AND DATE(fecha) BETWEEN ? AND ?
                 ) as dates
                 ORDER BY dates.dia ASC";
    $stmt = $pdo->prepare($sqlDaily);
    $stmt->execute([
        $id_sucursal, $id_sucursal, $id_sucursal, $id_sucursal, $id_sucursal,
        $id_sucursal, $fecha_inicio, $fecha_fin, 
        $id_sucursal, $fecha_inicio, $fecha_fin
    ]);
    $dailySummary = $stmt->fetchAll();

    // TOTALES CONSOLIDADOS
    $venta_total = 0;
    $costo_total = 0;
    $gastos_totales = 0;
    $total_transacciones = 0;
    $total_transferencias = 0;
    
    foreach($dailySummary as $row) {
        $venta_total += floatval($row['total_venta']);
        $costo_total += floatval($row['total_costo']);
        $gastos_totales += floatval($row['total_gasto']);
        $total_transacciones += intval($row['num_transacciones']);
        $total_transferencias += floatval($row['total_transferencia']);
    }

    $ganancia_bruta = $venta_total - $costo_total;
    $reserva_monto = $venta_total * ($pct_reserva / 100);
    $ganancia_limpia = $ganancia_bruta - $reserva_monto - $gastos_totales;
    $pct_margen_bruto = $venta_total > 0 ? ($ganancia_bruta / $venta_total) * 100 : 0;

    // PAGOS POR MÉTODO
    $stmt = $pdo->prepare("SELECT vp.metodo_pago, SUM(vp.monto) as total 
                           FROM ventas_pagos vp
                           JOIN ventas_cabecera vc ON vp.id_venta_cabecera = vc.id
                           WHERE vc.id_sucursal = ? AND DATE(vc.fecha) BETWEEN ? AND ?
                           GROUP BY vp.metodo_pago");
    $stmt->execute([$id_sucursal, $fecha_inicio, $fecha_fin]);
    $payments = $stmt->fetchAll();

    // --- CÁLCULO DE PROMEDIOS ---
    // Calculamos los días operativos reales (días donde hubo venta o gasto)
    $dias_operativos = count($dailySummary);
    if ($dias_operativos < 1) $dias_operativos = 1; // Evitar div by zero

    $promedio_venta_diaria = $venta_total / $dias_operativos;
    $promedio_ganancia_bruta_diaria = $ganancia_bruta / $dias_operativos; // Nuevo Cálculo
    $promedio_ganancia_diaria = $ganancia_limpia / $dias_operativos;
    $promedio_gasto_diario = $gastos_totales / $dias_operativos;
    
    // Ticket Promedio (Venta Total / Cantidad de Tickets)
    $ticket_promedio = ($total_transacciones > 0) ? ($venta_total / $total_transacciones) : 0;

    // --- CARGAR HISTORIAL DE REPORTES ---
    $stmt = $pdo->prepare("SELECT * FROM reportes_cierre WHERE id_sucursal = ? ORDER BY fecha_fin DESC, id DESC LIMIT 20");
    $stmt->execute([$id_sucursal]);
    $historial_reportes = $stmt->fetchAll();

    $sessionSummary = [];
    $ticketDetailsBySale = [];

    $stmt = $pdo->prepare("SELECT *
                           FROM ventas_cabecera
                           WHERE id_sucursal = ? AND DATE(fecha) BETWEEN ? AND ?
                           ORDER BY fecha ASC, id ASC");
    $stmt->execute([$id_sucursal, $fecha_inicio, $fecha_fin]);
    $sessionTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($sessionTickets)) {
        $saleIds = array_map(static function ($ticket) {
            return intval($ticket['id']);
        }, $sessionTickets);
        $placeholders = implode(',', array_fill(0, count($saleIds), '?'));

        $stmt = $pdo->prepare("SELECT id_venta_cabecera, nombre_producto, cantidad, precio
                               FROM ventas_detalle
                               WHERE id_venta_cabecera IN ($placeholders)
                               ORDER BY id_venta_cabecera ASC, id ASC");
        $stmt->execute($saleIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $detail) {
            $saleId = intval($detail['id_venta_cabecera']);
            if (!isset($ticketDetailsBySale[$saleId])) {
                $ticketDetailsBySale[$saleId] = [];
            }
            $ticketDetailsBySale[$saleId][] = $detail;
        }

        foreach ($sessionTickets as $ticket) {
            $sessionId = business_report_ticket_session_id($ticket);
            if ($sessionId <= 0) {
                continue;
            }
            if (!isset($sessionSummary[$sessionId])) {
                $sessionSummary[$sessionId] = [
                    'id' => $sessionId,
                    'nombre_cajero' => '',
                    'fecha_apertura' => null,
                    'fecha_cierre' => null,
                    'fecha_contable' => null,
                    'estado' => '',
                    'tickets' => [],
                    'total' => 0.0,
                ];
            }
            $sessionSummary[$sessionId]['tickets'][] = $ticket;
            $sessionSummary[$sessionId]['total'] += floatval($ticket['total'] ?? 0);
        }

        if (!empty($sessionSummary)) {
            $sessionIds = array_keys($sessionSummary);
            $sessionPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $stmt = $pdo->prepare("SELECT id, nombre_cajero, fecha_apertura, fecha_cierre, fecha_contable, estado
                                   FROM caja_sesiones
                                   WHERE id IN ($sessionPlaceholders)
                                   ORDER BY fecha_apertura ASC, id ASC");
            $stmt->execute($sessionIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sessionId = intval($row['id']);
                if (!isset($sessionSummary[$sessionId])) {
                    continue;
                }
                $sessionSummary[$sessionId]['nombre_cajero'] = $row['nombre_cajero'] ?? '';
                $sessionSummary[$sessionId]['fecha_apertura'] = $row['fecha_apertura'] ?? null;
                $sessionSummary[$sessionId]['fecha_cierre'] = $row['fecha_cierre'] ?? null;
                $sessionSummary[$sessionId]['fecha_contable'] = $row['fecha_contable'] ?? null;
                $sessionSummary[$sessionId]['estado'] = $row['estado'] ?? '';
            }
        }
    }

} catch (Exception $e) { $error = $e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cierre de Negocio | <?= htmlspecialchars(config_loader_system_name()) ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <?php require_once __DIR__ . '/theme.php'; ?>
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .table thead th { white-space: nowrap; }
        .kpi-card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .bg-gradient-primary { background: linear-gradient(135deg, #0d6efd, #0a58ca); color: white; }
        .bg-gradient-success { background: linear-gradient(135deg, #198754, #146c43); color: white; }
        .bg-gradient-danger { background: linear-gradient(135deg, #dc3545, #b02a37); color: white; }
        .bg-gradient-dark { background: linear-gradient(135deg, #212529, #000000); color: white; }
        .table-resumen thead th { background: #e9ecef; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.5px; }
        .badge-header { font-size: 0.75rem; padding: 5px 12px; border-radius: 20px; background: rgba(255,255,255,0.2); }
        .small-pct { font-size: 0.85rem; background: rgba(255,255,255,0.25); padding: 2px 8px; border-radius: 10px; margin-left: 5px; font-weight: bold; }
        .btn-print-custom { background-color: #6610f2; color: white; border: none; }
        .btn-print-custom:hover { background-color: #520dc2; color: white; }
        .btn-nav-date { font-size: 0.8rem; padding: 2px 8px; }
        .session-summary-card { border: 1px solid rgba(13, 110, 253, 0.14); border-radius: 14px; overflow: hidden; }
        .session-summary-head { background: linear-gradient(135deg, rgba(13, 110, 253, 0.10), rgba(25, 135, 84, 0.08)); padding: 14px 18px; border-bottom: 1px solid rgba(13, 110, 253, 0.12); }
        .session-summary-meta { display: flex; flex-wrap: wrap; gap: 14px; font-size: 0.82rem; color: #6c757d; margin-top: 6px; }
        .ticket-items-list { font-size: 0.82rem; color: #495057; line-height: 1.45; }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">

    <?php echo $mensaje_accion; ?>

    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Contabilidad / Cierres</div>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-chart-line me-2"></i>Cierre de Negocio</h1>
                <p class="mb-3 text-white-50">Análisis de rentabilidad, desglose diario y métricas de rendimiento por periodo.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-store me-1"></i>Sucursal: <?php echo $id_sucursal; ?></span>
                    <span class="kpi-chip"><i class="fas fa-warehouse me-1"></i>Almacén: <?php echo $id_almacen; ?></span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-home me-1"></i>Volver</a>
                <a href="print_report.php?fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" target="_blank" class="btn btn-light fw-bold"><i class="fas fa-print me-1"></i>IMPRIMIR A4</a>
            </div>
        </div>
    </section>

    <div class="glass-card p-4 mb-4 inventory-fade-in">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="small fw-bold text-muted">Desde</label>
                <input type="date" name="fecha_inicio" class="form-control form-control-sm" value="<?php echo $fecha_inicio; ?>">
            </div>
            <div class="col-md-2">
                <label class="small fw-bold text-muted">Hasta</label>
                <input type="date" name="fecha_fin" class="form-control form-control-sm" value="<?php echo $fecha_fin; ?>">
            </div>
            <div class="col-md-5">
                <div class="btn-group btn-group-sm">
                    <a href="?periodo=week" class="btn btn-outline-primary">Semana</a>
                    <a href="?periodo=month" class="btn btn-outline-primary">Mes</a>
                    <a href="?periodo=year" class="btn btn-outline-primary">Año</a>
                    <a href="?periodo=30d" class="btn btn-outline-primary">30 Días</a>
                </div>
                <button type="submit" class="btn btn-primary btn-sm ms-2 px-3 fw-bold">FILTRAR</button>
            </div>
        </form>
        
        <div class="row mt-3">
            <div class="col-md-4">
                <div class="btn-group btn-group-sm">
                    <a href="?fecha_inicio=<?php echo $prev30_ini; ?>&fecha_fin=<?php echo $prev30_fin; ?>" class="btn btn-outline-secondary btn-nav-date" title="Retroceder 30 días"><i class="fas fa-angle-double-left"></i> -30d</a>
                    <a href="?fecha_inicio=<?php echo $prev7_ini; ?>&fecha_fin=<?php echo $prev7_fin; ?>" class="btn btn-outline-secondary btn-nav-date" title="Retroceder 7 días"><i class="fas fa-angle-left"></i> -7d</a>
                    <a href="?fecha_inicio=<?php echo $next7_ini; ?>&fecha_fin=<?php echo $next7_fin; ?>" class="btn btn-outline-secondary btn-nav-date" title="Avanzar 7 días">+7d <i class="fas fa-angle-right"></i></a>
                    <a href="?fecha_inicio=<?php echo $next30_ini; ?>&fecha_fin=<?php echo $next30_fin; ?>" class="btn btn-outline-secondary btn-nav-date" title="Avanzar 30 días">+30d <i class="fas fa-angle-double-right"></i></a>
                </div>
            </div>
        </div>
        <form method="POST" class="mt-3 text-end border-top pt-3">
            <input type="hidden" name="action" value="guardar">
            <input type="hidden" name="f_inicio" value="<?php echo $fecha_inicio; ?>">
            <input type="hidden" name="f_fin" value="<?php echo $fecha_fin; ?>">
            <input type="hidden" name="v_total" value="<?php echo $venta_total; ?>">
            <input type="hidden" name="g_neta" value="<?php echo $ganancia_limpia; ?>">
            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-save"></i> Guardar este Reporte en Historial</button>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card kpi-card bg-gradient-primary h-100">
                <div class="card-body">
                    <h6 class="small text-uppercase opacity-75">Venta Total</h6>
                    <h2 class="fw-bold mb-0">$<?php echo number_format($venta_total, 2); ?></h2>
                    <small class="opacity-75">Ingresos brutos</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-gradient-success h-100">
                <div class="card-body">
                    <h6 class="small text-uppercase opacity-75">Ganancia Bruta <span class="small-pct"><?php echo number_format($pct_margen_bruto, 1); ?>%</span></h6>
                    <h2 class="fw-bold mb-0">$<?php echo number_format($ganancia_bruta, 2); ?></h2>
                    <small class="opacity-75">Venta - Costo Mercancía</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-gradient-danger h-100">
                <div class="card-body">
                    <h6 class="small text-uppercase opacity-75">Gastos Operativos</h6>
                    <h2 class="fw-bold mb-0">$<?php echo number_format($gastos_totales, 2); ?></h2>
                    <small class="opacity-75">Total de gastos</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-dark text-white h-100 border-warning border-start border-4">
                <div class="card-body">
                    <h6 class="small text-uppercase text-warning fw-bold">Ganancia Limpia</h6>
                    <h2 class="fw-bold mb-0">$<?php echo number_format($ganancia_limpia, 2); ?></h2>
                    <small class="text-white-50">Reserva (<?php echo $pct_reserva; ?>%): $<?php echo number_format($reserva_monto, 2); ?></small>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="glass-card shadow-sm">
                <div class="card-header bg-transparent fw-bold">
                    <i class="fas fa-calendar-alt text-primary me-2"></i> DESGLOSE DIARIO DE OPERACIONES
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-resumen mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th class="text-end">Ventas ($)</th>
                                    <th class="text-end">Transf. ($)</th>
                                    <th class="text-end text-muted">Costo Merc. ($)</th>
                                    <th class="text-end">Ganancia Bruta ($)</th>
                                    <th class="text-end text-danger">Gastos ($)</th>
                                    <th class="text-end fw-bold text-success">Saldo Neto ($)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($dailySummary as $row): 
                                    $v = floatval($row['total_venta']);
                                    $t = floatval($row['total_transferencia']);
                                    $c = floatval($row['total_costo']);
                                    $e = floatval($row['total_gasto']);
                                    $gb = $v - $c;
                                    $sn = $gb - $e;
                                ?>
                                <tr>
                                    <td class="small fw-bold"><?php echo $row['dia']; ?></td>
                                    <td class="text-end">$<?php echo number_format($v, 2); ?></td>
                                    <td class="text-end text-primary">$<?php echo number_format($t, 2); ?></td>
                                    <td class="text-end text-muted">$<?php echo number_format($c, 2); ?></td>
                                    <td class="text-end">$<?php echo number_format($gb, 2); ?></td>
                                    <td class="text-end text-danger">$<?php echo number_format($e, 2); ?></td>
                                    <td class="text-end fw-bold text-success">$<?php echo number_format($sn, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light fw-bold border-top">
                                <tr>
                                    <td>TOTALES</td>
                                    <td class="text-end">$<?php echo number_format($venta_total, 2); ?></td>
                                    <td class="text-end text-primary">$<?php echo number_format($total_transferencias, 2); ?></td>
                                    <td class="text-end text-muted">$<?php echo number_format($costo_total, 2); ?></td>
                                    <td class="text-end">$<?php echo number_format($ganancia_bruta, 2); ?></td>
                                    <td class="text-end text-danger">$<?php echo number_format($gastos_totales, 2); ?></td>
                                    <td class="text-end text-success">$<?php echo number_format($ganancia_bruta - $gastos_totales, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-8">
            <div class="glass-card mb-4">
                <div class="card-header bg-transparent fw-bold">Tendencia de Ventas Diarias</div>
                <div class="card-body">
                    <canvas id="dailyChart" height="100"></canvas>
                </div>
            </div>
            
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="glass-card">
                        <div class="card-header bg-transparent fw-bold">Métodos de Pago</div>
                        <div class="card-body">
                            <canvas id="paymentChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="glass-card h-100">
                        <div class="card-header bg-transparent fw-bold text-primary">
                            <i class="fas fa-calculator me-1"></i> Métricas de Rendimiento Diario
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted small fw-bold text-uppercase">Promedio Venta Diaria:</span>
                                <span class="fw-bold text-dark fs-5">$<?php echo number_format($promedio_venta_diaria, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted small fw-bold text-uppercase">Promedio Ganancia (Bruta):</span>
                                <span class="fw-bold text-primary fs-5">$<?php echo number_format($promedio_ganancia_bruta_diaria, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted small fw-bold text-uppercase">Promedio Ganancia (Neta):</span>
                                <span class="fw-bold text-success fs-5">$<?php echo number_format($promedio_ganancia_diaria, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted small fw-bold text-uppercase">Promedio Gasto Diario:</span>
                                <span class="fw-bold text-danger fs-5">$<?php echo number_format($promedio_gasto_diario, 2); ?></span>
                            </div>
                            <hr class="my-2">
                            <div class="text-center mt-3">
                                <h4 class="fw-bold text-info mb-0">$<?php echo number_format($ticket_promedio, 2); ?></h4>
                                <small class="text-muted fw-bold">TICKET PROMEDIO POR CLIENTE</small>
                            </div>
                        </div>
                    </div>
                </div>
                </div>
        </div>

        <div class="col-md-4">
            <div class="glass-card h-100">
                <div class="card-header bg-transparent fw-bold">Resumen Detallado de Pagos</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Método</th>
                                <th class="text-end">Monto Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($payments as $p): ?>
                            <tr>
                                <td class="fw-bold"><?php echo $p['metodo_pago']; ?></td>
                                <td class="text-end fw-bold">$<?php echo number_format($p['total'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="p-3 bg-light mt-auto border-top">
                        <small class="text-muted d-block mb-1">Deducción para Reserva (<?php echo $pct_reserva; ?>%):</small>
                        <h5 class="fw-bold text-danger">$<?php echo number_format($reserva_monto, 2); ?></h5>
                        <p class="small text-muted m-0">Basado en la venta bruta del periodo.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 mb-4">
            <div class="glass-card shadow-sm">
                <div class="card-header bg-transparent fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-cash-register text-primary me-2"></i> RESUMEN POR SESIONES DE CAJA</span>
                    <span class="badge bg-primary-subtle text-primary-emphasis"><?php echo count($sessionSummary); ?> sesiones</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($sessionSummary)): ?>
                        <?php foreach ($sessionSummary as $session): ?>
                            <div class="session-summary-card mb-4">
                                <div class="session-summary-head">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                                        <div>
                                            <div class="fw-bold fs-5">Sesión #<?php echo intval($session['id']); ?><?php if (!empty($session['nombre_cajero'])): ?> · <?php echo htmlspecialchars($session['nombre_cajero']); ?><?php endif; ?></div>
                                            <div class="session-summary-meta">
                                                <span>Fecha contable: <?php echo !empty($session['fecha_contable']) ? date('d/m/Y', strtotime($session['fecha_contable'])) : 'N/D'; ?></span>
                                                <span>Apertura: <?php echo !empty($session['fecha_apertura']) ? date('d/m/Y H:i', strtotime($session['fecha_apertura'])) : 'N/D'; ?></span>
                                                <span>Cierre: <?php echo !empty($session['fecha_cierre']) ? date('d/m/Y H:i', strtotime($session['fecha_cierre'])) : 'Abierta'; ?></span>
                                                <span>Estado: <?php echo htmlspecialchars($session['estado'] ?: 'N/D'); ?></span>
                                                <span>Tickets: <?php echo count($session['tickets']); ?></span>
                                            </div>
                                        </div>
                                        <div class="text-lg-end">
                                            <small class="text-muted d-block">Total sesión</small>
                                            <div class="fw-bold fs-4 text-primary">$<?php echo number_format($session['total'], 2); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th># Ticket</th>
                                                <th>Hora</th>
                                                <th>Cliente</th>
                                                <th>Detalle agrupado</th>
                                                <th class="text-end">Valor</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($session['tickets'] as $ticket): ?>
                                                <?php
                                                $details = $ticketDetailsBySale[intval($ticket['id'])] ?? [];
                                                $groupedItems = [];
                                                foreach ($details as $detail) {
                                                    $name = trim((string)($detail['nombre_producto'] ?? 'Producto'));
                                                    $qty = floatval($detail['cantidad'] ?? 0);
                                                    $price = floatval($detail['precio'] ?? 0);
                                                    $key = $name . '|' . number_format($price, 2, '.', '');
                                                    if (!isset($groupedItems[$key])) {
                                                        $groupedItems[$key] = [
                                                            'name' => $name,
                                                            'qty' => 0.0,
                                                            'price' => $price,
                                                        ];
                                                    }
                                                    $groupedItems[$key]['qty'] += $qty;
                                                }
                                                ?>
                                                <tr>
                                                    <td class="fw-bold">#<?php echo intval($ticket['id']); ?></td>
                                                    <td><?php echo date('d/m H:i', strtotime($ticket['fecha'])); ?></td>
                                                    <td><?php echo htmlspecialchars($ticket['cliente_nombre'] ?: 'Cliente general'); ?></td>
                                                    <td class="ticket-items-list">
                                                        <?php if (empty($groupedItems)): ?>
                                                            <span class="text-muted">Sin detalle</span>
                                                        <?php else: ?>
                                                            <?php
                                                            $lines = [];
                                                            foreach ($groupedItems as $item) {
                                                                $lines[] = htmlspecialchars($item['name']) . ' x' . number_format($item['qty'], 2) . ' @ $' . number_format($item['price'], 2);
                                                            }
                                                            echo implode('<br>', $lines);
                                                            ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end fw-bold">$<?php echo number_format($ticket['total'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="4">Total sesión #<?php echo intval($session['id']); ?></th>
                                                <th class="text-end">$<?php echo number_format($session['total'], 2); ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">No hay tickets asociados a sesiones de caja en este rango.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="glass-card shadow-sm border-top border-3 border-secondary">
                <div class="card-header bg-transparent fw-bold d-flex justify-content-between">
                    <span><i class="fas fa-history text-secondary me-2"></i> HISTORIAL DE CIERRES GUARDADOS</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha Creación</th>
                                    <th>Periodo Abarcado</th>
                                    <th class="text-end">Venta Registrada</th>
                                    <th class="text-end">Ganancia Neta</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($historial_reportes) > 0): ?>
                                    <?php foreach($historial_reportes as $rep): ?>
                                    <tr>
                                        <td>#<?php echo $rep['id']; ?></td>
                                        <td class="small"><?php echo date('d/m/Y H:i', strtotime($rep['fecha_creacion'])); ?></td>
                                        <td class="fw-bold text-primary">
                                            <?php echo date('d/m/Y', strtotime($rep['fecha_inicio'])); ?> al 
                                            <?php echo date('d/m/Y', strtotime($rep['fecha_fin'])); ?>
                                        </td>
                                        <td class="text-end">$<?php echo number_format($rep['venta_total'], 2); ?></td>
                                        <td class="text-end fw-bold text-success">$<?php echo number_format($rep['ganancia_neta'], 2); ?></td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="?fecha_inicio=<?php echo $rep['fecha_inicio']; ?>&fecha_fin=<?php echo $rep['fecha_fin']; ?>" class="btn btn-outline-primary" title="Cargar Datos"><i class="fas fa-eye"></i></a>
                                                <form method="POST" onsubmit="return confirm('¿Eliminar este reporte permanentemente?');" style="display:inline;">
                                                    <input type="hidden" name="action" value="borrar">
                                                    <input type="hidden" name="id_reporte" value="<?php echo $rep['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-3 text-muted">No hay reportes guardados aún.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <br><br>
</div>

<script>
    new Chart(document.getElementById('dailyChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($dailySummary, 'dia')); ?>,
            datasets: [{
                label: 'Ventas ($)',
                data: <?php echo json_encode(array_column($dailySummary, 'total_venta')); ?>,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.2
            }]
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });

    new Chart(document.getElementById('paymentChart'), {
        type: 'pie',
        data: {
            labels: <?php echo json_encode(array_column($payments, 'metodo_pago')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($payments, 'total')); ?>,
                backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#0dcaf0', '#6610f2']
            }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
</script>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
