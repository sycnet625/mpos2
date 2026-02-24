<?php
// ARCHIVO: /var/www/palweb/api/dashboard.php

// ---------------------------------------------------------
// 🔒 SEGURIDAD: VERIFICACIÓN DE SESIÓN
// ---------------------------------------------------------
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 1. CONFIGURACIÓN Y CONEXIÓN
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once 'db.php';
    date_default_timezone_set('America/Havana');
    $pdo->exec("SET time_zone = '-05:00';");
} catch (Exception $e) {
    die("Error crítico de base de datos: " . $e->getMessage());
}

require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);

// --- DETERMINAR ALCANCE (SCOPE) ---
// global = Toda la empresa | local = Sucursal/Almacén actual
$scope = isset($_GET['scope']) ? $_GET['scope'] : 'local'; 

// --- FUNCIONES AUXILIARES ---
function getScalar($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) { return 0; }
}

function getRows($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// --- CHEQUEO DE SISTEMA ---
function getSystemStatus() {
    $imgDir = '/var/www/assets/product_images/';
    return [
        'gd' => extension_loaded('gd'),
        'zip' => class_exists('ZipArchive'),
        'pdo' => extension_loaded('pdo_mysql'),
        'folder' => is_writable($imgDir),
        'img_path' => $imgDir
    ];
}

// --- PARÁMETROS Y FILTROS DE FECHA ---
$fechaFinDef = date('Y-m-d');
$fechaIniDef = date('Y-m-d', strtotime('-7 days'));
$fechaInicio = isset($_GET['start']) && !empty($_GET['start']) ? $_GET['start'] : $fechaIniDef;
$fechaFin    = isset($_GET['end']) && !empty($_GET['end']) ? $_GET['end'] : $fechaFinDef;

// ============================================================================
//   2. LÓGICA DE ESTADÍSTICAS (KPIs)
// ============================================================================

// --- CONSTRUCCIÓN DE FILTROS SQL SEGÚN SCOPE ---
// Filtros Base
$sqlEmpresa = " AND p.id_empresa = $EMP_ID ";

// Filtros Inventario
$sqlAlmacen = ($scope === 'local') ? " AND s.id_almacen = $ALM_ID " : ""; 

// Filtros Ventas
$sqlSucursal = ($scope === 'local') ? " AND v.id_sucursal = $SUC_ID " : "";
$sqlDateRange = " AND DATE(v.fecha) BETWEEN ? AND ? ";

// A. Inventario (COLLATE fix: stock_almacen.id_producto usa uca1400, productos.codigo usa unicode_ci)
$sqlInvBase = "SELECT SUM(s.cantidad * %FIELD%) FROM stock_almacen s JOIN productos p ON s.id_producto = p.codigo WHERE 1=1 $sqlEmpresa $sqlAlmacen";
$valorInventarioCosto = getScalar($pdo, str_replace('%FIELD%', 'p.costo', $sqlInvBase));
$valorInventarioVenta = getScalar($pdo, str_replace('%FIELD%', 'p.precio', $sqlInvBase));

$sqlStockCritico = "SELECT COUNT(*) FROM stock_almacen s JOIN productos p ON s.id_producto = p.codigo WHERE s.cantidad <= p.stock_minimo $sqlEmpresa $sqlAlmacen";
$stockCritico = getScalar($pdo, $sqlStockCritico);
$margenPotencial = $valorInventarioVenta - $valorInventarioCosto;

// B. Ventas (Periodo)
$paramsDate = [$fechaInicio, $fechaFin];

$sqlVentasBase = "SELECT SUM(v.total) FROM ventas_cabecera v WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange";
$ventasPeriodo = getScalar($pdo, $sqlVentasBase, $paramsDate);

$sqlGanancia = "SELECT SUM((d.precio - p.costo) * d.cantidad)
                FROM ventas_detalle d
                JOIN productos p ON d.id_producto = p.codigo
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange";
$gananciaPeriodo = getScalar($pdo, $sqlGanancia, $paramsDate);

// --- C. MÉTRICAS WEB (AMPLIADO) ---
$totalVisitas = getScalar($pdo, "SELECT COUNT(*) FROM metricas_web");
$visitasHoy = getScalar($pdo, "SELECT COUNT(*) FROM metricas_web WHERE DATE(fecha) = CURRENT_DATE");
$ipsUnicas = getScalar($pdo, "SELECT COUNT(DISTINCT ip) FROM metricas_web");
$usuariosRegistrados = getScalar($pdo, "SELECT COUNT(*) FROM clientes_tienda");
$totalVistasProductos = getScalar($pdo, "SELECT COUNT(*) FROM vistas_productos");

// Rankings
$paginasPopulares = getRows($pdo, "SELECT url_visitada, COUNT(*) as visitas FROM metricas_web GROUP BY url_visitada ORDER BY visitas DESC LIMIT 5");
$topIps = getRows($pdo, "SELECT ip, COUNT(*) as hits FROM metricas_web GROUP BY ip ORDER BY hits DESC LIMIT 5");
$topPaises = getRows($pdo, "SELECT pais, COUNT(*) as visitas FROM metricas_web GROUP BY pais ORDER BY visitas DESC LIMIT 5");
$topVistos = getRows($pdo, "SELECT p.nombre, COUNT(v.id) as vistas 
                            FROM vistas_productos v 
                            JOIN productos p ON v.codigo_producto = p.codigo 
                            GROUP BY v.codigo_producto ORDER BY vistas DESC LIMIT 5");

$visitantesRecientes = getRows($pdo, "SELECT ip, url_visitada, fecha FROM metricas_web ORDER BY fecha DESC LIMIT 5");
$carritosAbandonadosCount = getScalar($pdo, "SELECT COUNT(*) FROM carritos_abandonados WHERE recuperado = 0 AND fecha_actualizacion < (NOW() - INTERVAL 1 HOUR)");
$carritosTotalValor = getScalar($pdo, "SELECT SUM(total) FROM carritos_abandonados WHERE recuperado = 0");

// Tasa de Conversión (Ventas Web / Visitas Únicas)
$ventasWebCount = getScalar($pdo, "SELECT COUNT(*) FROM pedidos_cabecera WHERE id_empresa = $EMP_ID");
$tasaConversion = ($ipsUnicas > 0) ? ($ventasWebCount / $ipsUnicas) * 100 : 0;

// URL más visitada (Solo el path relativo)
$urlMasVisitada = getScalar($pdo, "SELECT url_visitada FROM metricas_web GROUP BY url_visitada ORDER BY COUNT(*) DESC LIMIT 1");
$urlMasVisitada = basename($urlMasVisitada) ?: '/';

// ── Datos para el tab de Promociones Push ──────────────────────────────────
$pushClientesCount   = 0;
$pushOperadorCount   = 0;
$pushTotalEnviadas   = 0;
$pushHistorialPromo  = [];
try {
    $pushClientesCount  = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE tipo = 'cliente'")->fetchColumn();
    $pushOperadorCount  = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE tipo IN ('operador','cocina')")->fetchColumn();
    $pushTotalEnviadas  = (int)$pdo->query("SELECT COUNT(*) FROM push_notifications WHERE tipo = 'cliente'")->fetchColumn();
    $pushHistorialPromo = $pdo->query(
        "SELECT titulo, cuerpo, url, created_at FROM push_notifications
          WHERE tipo = 'cliente' ORDER BY created_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* tablas aún no creadas */ }

// Alertas: reservas sin stock y pagos pendientes de verificación
$reservasSinStock = 0;
$pagosVerificando = 0;
try {
    $reservasSinStock = (int)$pdo->query(
        "SELECT COUNT(*) FROM ventas_cabecera
         WHERE tipo_servicio='reserva' AND sin_existencia=1
           AND (estado_reserva IS NULL OR estado_reserva='PENDIENTE')"
    )->fetchColumn();
    $pagosVerificando = (int)$pdo->query(
        "SELECT COUNT(*) FROM ventas_cabecera
         WHERE estado_pago='verificando'
           AND (estado_reserva IS NULL OR estado_reserva='PENDIENTE')"
    )->fetchColumn();
} catch (Throwable $e) { /* columnas pueden no existir aún */ }

// ============================================================================
//   2.5 ANÁLISIS DE LOGS DE SERVIDOR (NGINX)
// ============================================================================
function parseLogStats($cmd) {
    $output = shell_exec($cmd);
    if (!$output) return [];
    $lines = explode("\n", trim($output));
    $data = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        // Formato esperado de 'uniq -c': "count value"
        if (preg_match('/^(\d+)\s+(.*)$/', $line, $matches)) {
            $data[] = ['count' => $matches[1], 'value' => $matches[2]];
        } else {
            $data[] = ['value' => $line];
        }
    }
    return $data;
}

$accessLogPath = '/var/log/nginx/palweb_access.log';
$errorLogPath = '/var/log/nginx/palweb_error.log';

$logIps = parseLogStats("tail -n 10000 $accessLogPath | awk '{print \$1}' | sort | uniq -c | sort -nr | head -n 10");
$logPages = parseLogStats("tail -n 10000 $accessLogPath | awk '{print \$7}' | sort | uniq -c | sort -nr | head -n 10");
$logBrowsers = parseLogStats("tail -n 10000 $accessLogPath | awk -F'\"' '{print \$6}' | sort | uniq -c | sort -nr | head -n 10");
$logAttacks = shell_exec("grep -Ei 'union.*select|sqlmap|etc/passwd|phpinfo|wp-login|config\.php|\.env' $accessLogPath | awk '{print \$1 \" -> \" \$7}' | tail -n 10");
$logErrors = shell_exec("tail -n 50 $errorLogPath | grep 'error' | tail -n 5");


$sqlTrans = "SELECT COUNT(*) FROM ventas_cabecera v WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange";
$totalTransacciones = getScalar($pdo, $sqlTrans, $paramsDate);
$ticketPromedio = ($totalTransacciones > 0) ? $ventasPeriodo / $totalTransacciones : 0;

// C. Pendientes (Pedidos Web - Globalmente o por sucursal si se implementara asignación)
$countPendientes = getScalar($pdo, "SELECT COUNT(*) FROM pedidos_cabecera WHERE estado = 'pendiente'", []);

// D. Datos para Gráficas
$sqlPagos = "SELECT COALESCE(metodo_pago, 'Efectivo') as metodo, SUM(total) as total 
             FROM ventas_cabecera v 
             WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange 
             GROUP BY metodo_pago ORDER BY total DESC";
$pagosData = getRows($pdo, $sqlPagos, $paramsDate);

$topProductos = getRows($pdo, "SELECT p.nombre, SUM(d.cantidad) as vendidos, SUM(d.cantidad * (d.precio - p.costo)) as ganancia
                               FROM ventas_detalle d
                               JOIN productos p ON d.id_producto = p.codigo
                               JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                               WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange
                               GROUP BY p.codigo ORDER BY vendidos DESC LIMIT 5", $paramsDate);

// ============================================================================
//   3. NUEVAS TARJETAS SOLICITADAS (ANÁLISIS PRODUCTO)
// ============================================================================

// 1. Productos con Mayor Ganancia Neta (Absoluta)
$sqlTopProfit = "SELECT p.nombre, SUM(d.cantidad * (d.precio - p.costo)) as total_ganancia
                 FROM ventas_detalle d
                 JOIN productos p ON d.id_producto = p.codigo
                 JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                 WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange
                 GROUP BY p.codigo
                 ORDER BY total_ganancia DESC LIMIT 5";
$topProfitProds = getRows($pdo, $sqlTopProfit, $paramsDate);

// 2. Productos con Menor Margen % (Que tengan stock > 0)
// Margen = ((Precio - Costo) / Precio) * 100
$sqlLowMargin = "SELECT p.nombre, p.precio, p.costo,
                 ((p.precio - p.costo) / NULLIF(p.precio,0)) * 100 as margen_porc
                 FROM stock_almacen s
                 JOIN productos p ON s.id_producto = p.codigo
                 WHERE p.id_empresa = $EMP_ID $sqlAlmacen AND s.cantidad > 0 AND p.precio > 0
                 ORDER BY margen_porc ASC LIMIT 5";
$lowMarginProds = getRows($pdo, $sqlLowMargin);

// 3. Lento Movimiento (Con stock, sin ventas en últimos 7 días)
// Ignora el filtro de fecha del dashboard, usa "Last 7 Days" fijo
$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$sqlSlow = "SELECT p.nombre, s.cantidad
            FROM stock_almacen s
            JOIN productos p ON s.id_producto = p.codigo
            WHERE p.id_empresa = $EMP_ID $sqlAlmacen AND s.cantidad > 0
            AND p.codigo NOT IN (
                SELECT d.id_producto
                FROM ventas_detalle d
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                WHERE v.fecha >= ? $sqlSucursal
            )
            LIMIT 5";
$slowMovingProds = getRows($pdo, $sqlSlow, [$sevenDaysAgo]);

// ============================================================================
//   4. NUEVAS TARJETAS: CATEGORÍAS
// ============================================================================

// 4.1 Ventas por Categoría
$sqlCatSales = "SELECT p.categoria, SUM(d.cantidad * d.precio) as total_venta
                FROM ventas_detalle d
                JOIN productos p ON d.id_producto = p.codigo
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange
                GROUP BY p.categoria
                ORDER BY total_venta DESC";
$catSalesData = getRows($pdo, $sqlCatSales, $paramsDate);

// 4.2 Ganancias por Categoría
$sqlCatProfit = "SELECT p.categoria, SUM(d.cantidad * (d.precio - p.costo)) as total_ganancia
                 FROM ventas_detalle d
                 JOIN productos p ON d.id_producto = p.codigo
                 JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                 WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange
                 GROUP BY p.categoria
                 ORDER BY total_ganancia DESC";
$catProfitData = getRows($pdo, $sqlCatProfit, $paramsDate);

// 4.3 Inventarios por Categoría (Cantidad y Valor Costo)
$sqlInvByCategory = "SELECT p.categoria, SUM(s.cantidad) as total_cantidad, SUM(s.cantidad * p.costo) as total_costo_valor
                     FROM stock_almacen s
                     JOIN productos p ON s.id_producto = p.codigo
                     WHERE 1=1 $sqlEmpresa $sqlAlmacen
                     GROUP BY p.categoria
                     ORDER BY total_costo_valor DESC"; // Order by total cost value
$invByCategoryData = getRows($pdo, $sqlInvByCategory);


// E. Lista Unificada de Pedidos Web y Reservas
$sqlOrders = "
    (SELECT
        id,
        cliente_nombre,
        cliente_telefono,
        cliente_direccion,
        fecha,
        fecha_programada,
        total,
        estado,
        notas,
        'WEB' as origen,
        'Web' as canal_origen
    FROM pedidos_cabecera)
    UNION ALL
    (SELECT
        id,
        cliente_nombre,
        cliente_telefono,
        cliente_direccion,
        fecha,
        fecha_reserva as fecha_programada,
        total,
        CASE COALESCE(estado_reserva, 'PENDIENTE')
            WHEN 'PENDIENTE'  THEN 'pendiente'
            WHEN 'ENTREGADO'  THEN 'completado'
            WHEN 'CANCELADO'  THEN 'cancelado'
            WHEN 'EN_CAMINO'  THEN 'camino'
            WHEN 'EN_PREPARACION' THEN 'proceso'
            ELSE 'pendiente'
        END as estado,
        notas,
        'POS' as origen,
        COALESCE(canal_origen, 'POS') as canal_origen
    FROM ventas_cabecera
    WHERE tipo_servicio = 'reserva')
    ORDER BY CASE WHEN estado = 'pendiente' THEN 1 ELSE 2 END, fecha DESC 
    LIMIT 100";
$pedidos = getRows($pdo, $sqlOrders, []);

// F. Sistema
$sysStatus = getSystemStatus();

function getStatusBadge($estado) {
    switch($estado) {
        case 'pendiente': return '<span class="badge bg-warning text-dark"><i class="fas fa-bell"></i> Nuevo</span>';
        case 'proceso':   return '<span class="badge bg-info text-dark"><i class="fas fa-fire"></i> Cocina</span>';
        case 'camino':    return '<span class="badge bg-primary"><i class="fas fa-motorcycle"></i> En Camino</span>';
        case 'completado':return '<span class="badge bg-success"><i class="fas fa-check"></i> Entregado</span>';
        case 'cancelado': return '<span class="badge bg-danger"><i class="fas fa-times"></i> Cancelado</span>';
        default:          return '<span class="badge bg-secondary">'.$estado.'</span>';
    }
}

function getCanalBadge($canal) {
    $map = [
        'Web'        => ['#0ea5e9', 'white', 'fa-globe',         'Web'],
        'POS'        => ['#6366f1', 'white', 'fa-cash-register', 'POS'],
        'WhatsApp'   => ['#22c55e', 'white', 'fa-whatsapp fab',  'WhatsApp'],
        'Teléfono'   => ['#f59e0b', 'white', 'fa-phone-alt',     'Tel.'],
        'Kiosko'     => ['#8b5cf6', 'white', 'fa-tablet-alt',    'Kiosko'],
        'Presencial' => ['#475569', 'white', 'fa-user',          'Presencial'],
        'ICS'        => ['#94a3b8', 'white', 'fa-file-import',   'ICS'],
        'Otro'       => ['#94a3b8', 'white', 'fa-question',      'Otro'],
    ];
    [$bg, $fg, $icon, $label] = $map[$canal] ?? $map['Otro'];
    // Separar fab/fas si viene junto
    $iconClass = str_contains($icon, ' ') ? $icon : "fas $icon";
    return "<span style=\"display:inline-flex;align-items:center;gap:4px;background-color:{$bg}!important;color:{$fg}!important;padding:3px 9px;border-radius:20px;font-size:.65rem;font-weight:700;white-space:nowrap;\"><i class=\"{$iconClass}\"></i>{$label}</span>";
}

// ============================================================================
//   DATOS DEL TAB AUDITORÍA
// ============================================================================
$auditRows       = [];
$auditKpi        = ['total' => 0, 'anulaciones' => 0, 'descuentos' => 0, 'devoluciones' => 0, 'integridad_ok' => 0, 'integridad_err' => 0];
$auditTabBadge   = 0;   // Para el badge rojo del tab

try {
    // Crear/migrar tabla si no existe (reutiliza lógica de pos_audit.php)
    $pdo->exec("CREATE TABLE IF NOT EXISTS auditoria_pos (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        accion     VARCHAR(50)  NOT NULL,
        usuario    VARCHAR(100) NOT NULL,
        datos      TEXT,
        ip         VARCHAR(45)  NULL,
        checksum   CHAR(40)     NULL,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_accion     (accion),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Últimas 500 filas dentro del rango de fechas del dashboard
    $stmtAudit = $pdo->prepare(
        "SELECT * FROM auditoria_pos
         WHERE (created_at BETWEEN ? AND ? OR created_at IS NULL)
         ORDER BY id DESC
         LIMIT 500"
    );
    $stmtAudit->execute([$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59']);
    $auditRows = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);

    foreach ($auditRows as $r) {
        $auditKpi['total']++;
        $accion = $r['accion'];
        if ($accion === 'VENTA_ANULADA')                            $auditKpi['anulaciones']++;
        if ($accion === 'DESCUENTO_ITEM' || $accion === 'DESCUENTO_GLOBAL') $auditKpi['descuentos']++;
        if ($accion === 'DEVOLUCION_ITEM' || $accion === 'DEVOLUCION_TICKET') $auditKpi['devoluciones']++;

        // Verificar integridad del checksum
        $datos = $r['datos'] ?? '';
        $ip    = $r['ip']    ?? '';
        $esperado = sha1($accion . '|' . $r['usuario'] . '|' . $datos . '|' . $ip);
        if ($r['checksum'] && $r['checksum'] === $esperado) {
            $auditKpi['integridad_ok']++;
        } elseif ($r['checksum']) {
            $auditKpi['integridad_err']++;
        }
    }

    // Badge del tab = anulaciones + descuentos en las últimas 24h
    $stmtBadge = $pdo->query(
        "SELECT COUNT(*) FROM auditoria_pos
         WHERE accion IN ('VENTA_ANULADA','DESCUENTO_ITEM','DESCUENTO_GLOBAL')
           AND created_at >= NOW() - INTERVAL 24 HOUR"
    );
    $auditTabBadge = (int)$stmtBadge->fetchColumn();

} catch (Throwable $auditEx) {
    // La tabla puede no existir aún — sin datos es suficiente
}

// Helpers de presentación del audit
function auditBadgeHtml(string $accion): string {
    $map = [
        'VENTA_GUARDADA'     => ['#198754', 'fa-receipt',       'VENTA'],
        'DESCUENTO_ITEM'     => ['#f59e0b', 'fa-tag',           'DESC. ITEM'],
        'DESCUENTO_GLOBAL'   => ['#e67e22', 'fa-percent',       'DESC. GLOBAL'],
        'VENTA_ANULADA'      => ['#dc3545', 'fa-ban',           'ANULADA'],
        'DEVOLUCION_ITEM'    => ['#6f42c1', 'fa-undo',          'DEV. ITEM'],
        'DEVOLUCION_TICKET'  => ['#9d174d', 'fa-times-circle',  'DEV. TICKET'],
        'SESION_ABIERTA'     => ['#0ea5e9', 'fa-door-open',     'APERTURA'],
        'SESION_CERRADA'     => ['#64748b', 'fa-door-closed',   'CIERRE'],
    ];
    [$color, $icon, $label] = $map[$accion] ?? ['#6c757d', 'fa-circle', $accion];
    return "<span style=\"background:{$color};color:#fff;padding:2px 9px;border-radius:20px;font-size:.65rem;font-weight:700;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;\"><i class=\"fas {$icon}\"></i>{$label}</span>";
}

function auditResumen(string $accion, array $d): string {
    switch ($accion) {
        case 'VENTA_ANULADA':
            $motivo = htmlspecialchars(substr($d['motivo'] ?? '—', 0, 60));
            $total  = '$' . number_format(abs($d['total'] ?? 0), 2);
            return "Ticket #" . ($d['id_venta'] ?? '?') . " — {$total} — <em class=\"text-muted\">\"{$motivo}\"</em>";
        case 'DESCUENTO_ITEM':
            $pct  = number_format($d['descuento_pct'] ?? 0, 0);
            $prod = htmlspecialchars(substr($d['producto'] ?? '?', 0, 30));
            $ori  = '$' . number_format($d['precio_original'] ?? 0, 2);
            $fin  = '$' . number_format($d['precio_final']    ?? 0, 2);
            return "#{$d['id_venta']} — {$prod} — <s>{$ori}</s> → {$fin} (-{$pct}%)";
        case 'DESCUENTO_GLOBAL':
            $pct  = number_format($d['descuento_pct'] ?? 0, 0);
            $net  = '$' . number_format($d['total_neto'] ?? 0, 2);
            return "Ticket #{$d['id_venta']} — -{$pct}% global → neto {$net}";
        case 'DEVOLUCION_ITEM':
            $prod = htmlspecialchars(substr($d['producto'] ?? '?', 0, 30));
            $m    = '$' . number_format($d['monto'] ?? 0, 2);
            return "Ticket #{$d['id_venta']} — {$prod} — {$m}";
        case 'DEVOLUCION_TICKET':
            $tot  = '$' . number_format(abs($d['total'] ?? 0), 2);
            return "Ticket #{$d['id_venta']} — Total {$tot} — " . ($d['items_count'] ?? '?') . " items";
        case 'SESION_ABIERTA':
        case 'SESION_CERRADA':
            return "Sesión #" . ($d['id_sesion'] ?? '?');
        default:
            $txt = json_encode($d, JSON_UNESCAPED_UNICODE);
            return htmlspecialchars(substr($txt, 0, 80));
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-ZM015S9N6M"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-ZM015S9N6M');
</script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin | PalWeb POS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style id="kpi-custom-styles">
        body { background-color: #f0f2f5 !important; font-family: 'Segoe UI', sans-serif; padding-bottom: 60px; }
        
        /* Selectores de máxima prioridad */
        body .card.card-stat { border: none !important; border-radius: 12px !important; transition: transform 0.2s !important; position: relative !important; overflow: hidden !important; }
        
        /* Clases de color con !important y prefijo de body para ganar especificidad */
        body .kpi-venta { background-color: #eef2ff !important; border-left: 5px solid #6366f1 !important; color: #1e1b4b !important; }
        body .kpi-costo { background-color: #f8fafc !important; border-left: 5px solid #94a3b8 !important; color: #1e293b !important; }
        body .kpi-margen { background-color: #f0fdf4 !important; border-left: 5px solid #22c55e !important; color: #14532d !important; }
        body .kpi-critico { background-color: #fef2f2 !important; border-left: 5px solid #ef4444 !important; color: #7f1d1d !important; }
        
        body .kpi-total-v { background-color: #4f46e5 !important; color: white !important; }
        body .kpi-ganancia { background-color: #10b981 !important; color: white !important; }
        body .kpi-trans { background-color: #f59e0b !important; color: white !important; }
        body .kpi-ticket { background-color: #8b5cf6 !important; color: white !important; }
        body .kpi-urls { background-color: #06b6d4 !important; color: white !important; }
        
        /* Nuevo estilo solicitado: Naranja con letras negras */
        body .kpi-orange { background-color: #ffc107 !important; color: #000 !important; }
        
        /* Nuevo estilo solicitado: Verde forzado */
        body .kpi-green { background-color: #28a745 !important; color: white !important; }

        .icon-stat { font-size: 2.5rem !important; opacity: 0.15 !important; position: absolute !important; right: 20px !important; top: 20px !important; }

        .table-orders tbody tr { transition: background 0.2s; }
        .table-orders tbody tr:hover { background-color: #f8f9fa; }
        .scheduled-date { background-color: #e3f2fd; color: #0d6efd; padding: 4px 8px; border-radius: 6px; font-weight: 600; display: inline-block; margin-top: 4px; font-size: 0.85rem; }
        .urgent-date { background-color: #ffe0e0; color: #d63384; }
        .sys-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .bg-ok { background-color: #198754; }
        .bg-fail { background-color: #dc3545; }

        /* Punto parpadeante live */
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.2} }
        .blink-dot { animation: blink 1.4s ease-in-out infinite; }

        /* Estilos para Tabs con deslizamiento (Slide Effect) */
        .tab-content { 
            position: relative; 
            overflow: hidden; 
            width: 100%;
            min-height: 500px;
        }
        .tab-pane { 
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
        }
        /* Estado inicial/oculto: desplazado a la derecha y transparente */
        .tab-pane:not(.active) { 
            display: block; 
            position: absolute;
            top: 0;
            opacity: 0; 
            transform: translateX(50px);
            pointer-events: none;
        }
        /* Estado activo: posición original y visible */
        .tab-pane.active { 
            display: block; 
            position: relative;
            opacity: 1; 
            transform: translateX(0);
        }
        
        /* Opcional: Pequeña animación para los botones de las tabs */
        .nav-pills .nav-link { 
            border-radius: 50px; 
            padding: 8px 25px; 
            margin-right: 5px;
            border: 1px solid transparent;
        }
        .nav-pills .nav-link.active {
            background-color: #0d6efd !important;
            color: white !important;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
        }

        /* ── Auditoría Tab ──────────────────────────────────── */
        .audit-table { font-size: 0.82rem; }
        .audit-table td { vertical-align: middle; padding: 6px 10px; }
        .audit-table tr.audit-anulada  { background: #fff5f5 !important; border-left: 4px solid #dc3545 !important; }
        .audit-table tr.audit-descuento { background: #fffbeb !important; border-left: 4px solid #f59e0b !important; }
        .audit-table tr.audit-devolucion { background: #f5f0ff !important; border-left: 4px solid #6f42c1 !important; }
        .audit-table tr.audit-sesion   { background: #f0f9ff !important; border-left: 4px solid #0ea5e9 !important; }
        .audit-table tr.audit-venta    { background: #f0fdf4 !important; border-left: 4px solid #198754 !important; }
        .audit-table tr.audit-other    { border-left: 4px solid #dee2e6 !important; }
        .audit-integrity-ok  { color: #198754; font-weight: 700; }
        .audit-integrity-err { color: #dc3545; font-weight: 700; }
        .audit-integrity-na  { color: #adb5bd; }
        .audit-filter-bar input, .audit-filter-bar select { font-size: 0.82rem; }
        .kpi-audit-anuladas  { background: #fef2f2 !important; border-left: 5px solid #dc3545 !important; }
        .kpi-audit-descuentos{ background: #fffbeb !important; border-left: 5px solid #f59e0b !important; }
        .kpi-audit-devol     { background: #f5f0ff !important; border-left: 5px solid #6f42c1 !important; }
        .kpi-audit-integ     { background: #f0fdf4 !important; border-left: 5px solid #198754 !important; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="#"><i class="fas fa-tachometer-alt me-2"></i>PalWeb Admin</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="pos_config.php"><i class="fas fa-cogs"></i> Configuración</a></li>
                <li class="nav-item ms-lg-3"> <a class="btn btn-danger btn-sm fw-bold" href="logout.php"><i class="fas fa-sign-out-alt me-1"></i> Salir </a></li>            
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid p-4">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <!-- Navegación por Tabs -->
        <ul class="nav nav-pills bg-light p-1 rounded shadow-sm" id="dashboardTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-negocios" type="button"><i class="fas fa-chart-line me-1"></i> Negocios</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-realtime" type="button" id="btnTabRealtime">
                    <i class="fas fa-satellite-dish me-1 text-danger blink-dot"></i> Tiempo Real
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-reservas" type="button"><i class="fas fa-calendar-check me-1"></i> Reservas</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-web" type="button"><i class="fas fa-shopping-cart me-1"></i> Web & Ecommerce</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-promo" type="button"><i class="fas fa-bullhorn me-1"></i> Promociones <?php if($pushClientesCount > 0): ?><span class="badge bg-primary ms-1"><?php echo $pushClientesCount; ?></span><?php endif; ?></button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-auditoria" type="button">
                    <i class="fas fa-shield-alt me-1"></i> Auditoría
                    <?php if($auditTabBadge > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $auditTabBadge; ?></span>
                    <?php endif; ?>
                </button>
            </li>
        </ul>

        <div class="d-flex gap-2">
            <div class="btn-group shadow-sm">
                <a href="?scope=local&start=<?php echo $fechaInicio; ?>&end=<?php echo $fechaFin; ?>" class="btn btn-sm <?php echo $scope === 'local' ? 'btn-primary fw-bold' : 'btn-outline-secondary bg-white'; ?>">
                    <i class="fas fa-store me-1"></i> Sucursal Actual (#<?php echo $SUC_ID; ?>)
                </a>
                <a href="?scope=global&start=<?php echo $fechaInicio; ?>&end=<?php echo $fechaFin; ?>" class="btn btn-sm <?php echo $scope === 'global' ? 'btn-primary fw-bold' : 'btn-outline-secondary bg-white'; ?>">
                    <i class="fas fa-globe me-1"></i> Global / Todos
                </a>
            </div>
            
            <form method="GET" class="d-flex align-items-center gap-2 bg-white p-1 px-2 rounded shadow-sm border">
                <input type="hidden" name="scope" value="<?php echo $scope; ?>">
                <input type="date" name="start" class="form-control form-control-sm w-auto border-0" value="<?php echo $fechaInicio; ?>">
                <span class="text-muted">-</span>
                <input type="date" name="end" class="form-control form-control-sm w-auto border-0" value="<?php echo $fechaFin; ?>">
                <button type="submit" class="btn btn-primary btn-sm rounded-circle"><i class="fas fa-filter"></i></button>
            </form>
        </div>
    </div>

    <div class="tab-content">
        <!-- TAB: TIEMPO REAL -->
        <div class="tab-pane fade" id="tab-realtime">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-uppercase fw-bold mb-0 ps-1" style="color:#ef4444">
                    <i class="fas fa-satellite-dish me-2 blink-dot"></i> Ventas en Tiempo Real — Hoy
                </h6>
                <small id="rt-timestamp" class="text-muted fst-italic"></small>
            </div>

            <!-- KPIs principales -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm h-100" style="background:#4f46e5;color:white">
                        <div class="card-body py-3">
                            <div class="small opacity-75 text-uppercase fw-bold">Ventas Hoy</div>
                            <div class="fs-4 fw-bold" id="rt-venta">—</div>
                            <div id="rt-vs-ayer" class="small mt-1 opacity-90">—</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm h-100" style="background:#10b981;color:white">
                        <div class="card-body py-3">
                            <div class="small opacity-75 text-uppercase fw-bold">Ganancia Neta</div>
                            <div class="fs-4 fw-bold" id="rt-ganancia">—</div>
                            <div id="rt-vs-sem" class="small mt-1 opacity-90">—</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm h-100" style="background:#f59e0b;color:white">
                        <div class="card-body py-3">
                            <div class="small opacity-75 text-uppercase fw-bold">Operaciones</div>
                            <div class="fs-4 fw-bold" id="rt-ops">—</div>
                            <div class="small opacity-75 mt-1">tickets hoy</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm h-100" style="background:#8b5cf6;color:white">
                        <div class="card-body py-3">
                            <div class="small opacity-75 text-uppercase fw-bold">Ticket Promedio</div>
                            <div class="fs-4 fw-bold" id="rt-ticket">—</div>
                            <div class="small opacity-75 mt-1">por operación</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm h-100" style="background:#0ea5e9;color:white">
                        <div class="card-body py-3">
                            <div class="small opacity-75 text-uppercase fw-bold">Mensajerías</div>
                            <div class="fs-4 fw-bold" id="rt-mensajerias">—</div>
                            <div class="small mt-1" id="rt-mensajerias-total">—</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm h-100" style="background:#f43f5e;color:white">
                        <div class="card-body py-3">
                            <div class="small opacity-75 text-uppercase fw-bold">Reservas</div>
                            <div class="fs-4 fw-bold" id="rt-reservas">—</div>
                            <div class="small mt-1" id="rt-reservas-total">—</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Canal de origen + Autoservicio + Última venta -->
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <div class="small text-uppercase text-muted fw-bold mb-2">Canal de Origen</div>
                            <div class="d-flex gap-3 flex-wrap">
                                <div class="text-center">
                                    <div class="fs-5 fw-bold text-primary" id="rt-canal-pos">—</div>
                                    <div class="small text-muted">POS</div>
                                </div>
                                <div class="text-center">
                                    <div class="fs-5 fw-bold text-success" id="rt-canal-web">—</div>
                                    <div class="small text-muted">Web</div>
                                </div>
                                <div class="text-center">
                                    <div class="fs-5 fw-bold" style="color:#25d366" id="rt-canal-wa">—</div>
                                    <div class="small text-muted">WhatsApp</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <div class="small text-uppercase text-muted fw-bold mb-1">Local / Mostrador</div>
                            <div class="fs-3 fw-bold text-dark" id="rt-autoservicio">—</div>
                            <div class="small text-muted" id="rt-autoservicio-total">—</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <div class="small text-uppercase text-muted fw-bold mb-1"><i class="fas fa-receipt me-1"></i> Última Venta</div>
                            <div id="rt-ultima-venta" class="small text-muted">—</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gráfico por hora + Cocina/Caja -->
            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold py-2 small">
                            <i class="fas fa-chart-bar text-primary me-2"></i> Ventas por Hora — Hoy vs Ayer
                        </div>
                        <div class="card-body">
                            <div style="height:220px"><canvas id="rt-chart-horas"></canvas></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-bold py-2 small">
                            <i class="fas fa-fire-alt text-danger me-2"></i> Cocina
                        </div>
                        <div class="card-body">
                            <div class="row g-2 text-center mb-3">
                                <div class="col-4">
                                    <div class="rounded p-2" style="background:#fff3cd">
                                        <div class="fw-bold fs-4 text-warning" id="rt-cocina-p">—</div>
                                        <div class="small text-muted">Pendientes</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="rounded p-2" style="background:#cff4fc">
                                        <div class="fw-bold fs-4 text-info" id="rt-cocina-e">—</div>
                                        <div class="small text-muted">En prep.</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="rounded p-2" style="background:#d1e7dd">
                                        <div class="fw-bold fs-4 text-success" id="rt-cocina-t">—</div>
                                        <div class="small text-muted">Listos</div>
                                    </div>
                                </div>
                            </div>
                            <div class="small text-uppercase text-muted fw-bold mb-2">
                                <i class="fas fa-cash-register me-1"></i> Caja(s) Activa(s)
                            </div>
                            <div id="rt-cajas">
                                <div class="text-muted text-center py-2 small">Cargando...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top 10 + Métodos de pago -->
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold py-2 small">
                            <i class="fas fa-crown text-warning me-2"></i> Top 10 Productos del Día
                        </div>
                        <ul class="list-group list-group-flush small" id="rt-top10">
                            <li class="list-group-item text-center text-muted">Cargando...</li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold py-2 small">
                            <i class="fas fa-wallet text-success me-2"></i> Recaudación por Método de Pago
                        </div>
                        <div class="card-body pb-1">
                            <div style="height:160px"><canvas id="rt-chart-pagos"></canvas></div>
                        </div>
                        <ul class="list-group list-group-flush small" id="rt-pagos-tabla"></ul>
                    </div>
                </div>
            </div>
        </div><!-- /tab-realtime -->

        <!-- TAB 1: NEGOCIOS -->
        <div class="tab-pane fade show active" id="tab-negocios">
            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-boxes me-2"></i> Estado del Inventario (<?php echo ucfirst($scope); ?>)</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card card-stat kpi-venta h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted fw-bold small text-uppercase">Valor (Venta)</h6>
                            <h3 class="fw-bold text-dark mb-0">$<?php echo number_format($valorInventarioVenta, 0); ?></h3>
                            <i class="fas fa-tags icon-stat text-primary"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-costo h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted fw-bold small text-uppercase">Valor (Costo)</h6>
                            <h3 class="fw-bold text-dark mb-0">$<?php echo number_format($valorInventarioCosto, 0); ?></h3>
                            <i class="fas fa-warehouse icon-stat text-secondary"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-margen h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted fw-bold small text-uppercase">Margen Potencial</h6>
                            <h3 class="fw-bold text-success mb-0">$<?php echo number_format($margenPotencial, 0); ?></h3>
                            <i class="fas fa-hand-holding-usd icon-stat text-success"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-critico h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted fw-bold small text-uppercase">Stock Bajo</h6>
                            <h3 class="fw-bold text-danger mb-0"><?php echo $stockCritico; ?> <small class="fs-6 text-muted">items</small></h3>
                            <i class="fas fa-exclamation-triangle icon-stat text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($reservasSinStock > 0 || $pagosVerificando > 0): ?>
            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-bell me-2 text-warning"></i> Alertas Operativas</h6>
            <div class="row g-4 mb-4">
                <?php if ($reservasSinStock > 0): ?>
                <div class="col-md-6">
                    <div class="card h-100 shadow-sm border-0" style="background:#fff3cd; border-left:5px solid #fd7e14 !important; border-left-width:5px;">
                        <div class="card-body d-flex align-items-center gap-3">
                            <div style="font-size:2.5rem;">📦</div>
                            <div>
                                <h6 class="text-uppercase fw-bold small text-muted mb-1">Reservas sin Existencias</h6>
                                <h3 class="fw-bold mb-1 text-warning"><?= $reservasSinStock ?> reserva<?= $reservasSinStock > 1 ? 's' : '' ?></h3>
                                <a href="reservas.php" class="btn btn-sm btn-outline-warning">Ver reservas →</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($pagosVerificando > 0): ?>
                <div class="col-md-6">
                    <div class="card h-100 shadow-sm border-0" style="background:#fff3cd; border-left:5px solid #ffc107 !important; border-left-width:5px;">
                        <div class="card-body d-flex align-items-center gap-3">
                            <div style="font-size:2.5rem;">💳</div>
                            <div>
                                <h6 class="text-uppercase fw-bold small text-muted mb-1">Pagos Pendientes</h6>
                                <h3 class="fw-bold mb-1 text-dark"><?= $pagosVerificando ?> pago<?= $pagosVerificando > 1 ? 's' : '' ?></h3>
                                <a href="reservas.php?estado=VERIFICANDO" class="btn btn-sm btn-outline-warning">Verificar →</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-chart-line me-2"></i> Rendimiento del Periodo</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card card-stat kpi-ganancia text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-uppercase fw-bold small opacity-75">Venta Total</h6>
                            <h2 class="display-6 fw-bold mb-0">$<?php echo number_format($ventasPeriodo, 0); ?></h2>
                            <i class="fas fa-dollar-sign icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-ganancia text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-uppercase fw-bold small opacity-75">Ganancia Neta</h6>
                            <h2 class="display-6 fw-bold mb-0">$<?php echo number_format($gananciaPeriodo, 0); ?></h2>
                            <i class="fas fa-wallet icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-ticket text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-uppercase fw-bold small opacity-75">Ticket Promedio</h6>
                            <h2 class="display-6 fw-bold mb-0">$<?php echo number_format($ticketPromedio, 2); ?></h2>
                            <i class="fas fa-receipt icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-trans text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-uppercase fw-bold small opacity-75">Operaciones</h6>
                            <h2 class="display-6 fw-bold mb-0"><?php echo number_format($totalTransacciones); ?></h2>
                            <i class="fas fa-shopping-cart icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-wallet text-primary me-2"></i> Métodos de Pago</div>
                        <div class="card-body">
                            <div style="height: 250px;"><canvas id="chartPagos"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-crown text-warning me-2"></i> Más Vendidos (Cantidad)</div>
                        <ul class="list-group list-group-flush">
                            <?php foreach($topProductos as $idx => $prod): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="badge bg-light text-dark border me-2">#<?php echo $idx + 1; ?></span>
                                    <?php echo htmlspecialchars($prod['nombre']); ?>
                                </div>
                                <div>
                                    <span class="badge bg-primary rounded-pill me-2"><?php echo $prod['vendidos']; ?> un</span>
                                    <small class="text-success fw-bold">+$<?php echo number_format($prod['ganancia'],0); ?></small>
                                </div>
                            </li>
                            <?php endforeach; ?>
                            <?php if(empty($topProductos)) echo '<li class="list-group-item text-center text-muted">Sin ventas en este periodo</li>'; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-search-dollar me-2"></i> Análisis de Producto (Top 5)</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 border-top border-success border-4">
                        <div class="card-header bg-white fw-bold text-success"><i class="fas fa-money-bill-wave me-1"></i> Mayor Ganancia Total</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($topProfitProds)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos</li>
                            <?php else: ?>
                                <?php foreach($topProfitProds as $p): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span class="text-truncate"><?php echo htmlspecialchars($p['nombre']); ?></span>
                                    <span class="fw-bold text-success">+$<?php echo number_format($p['total_ganancia'], 0); ?></span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 border-top border-danger border-4">
                        <div class="card-header bg-white fw-bold text-danger"><i class="fas fa-percent me-1"></i> Menor Margen %</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($lowMarginProds)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos</li>
                            <?php else: ?>
                                <?php foreach($lowMarginProds as $p): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span class="text-truncate" title="P: $<?php echo $p['precio']; ?> C: $<?php echo $p['costo']; ?>"><?php echo htmlspecialchars($p['nombre']); ?></span>
                                    <span class="fw-bold text-danger"><?php echo number_format($p['margen_porc'], 1); ?>%</span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 border-top border-warning border-4">
                        <div class="card-header bg-white fw-bold text-warning"><i class="fas fa-hourglass-half me-1"></i> Lento Movimiento (7 días)</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($slowMovingProds)): ?>
                                <li class="list-group-item text-center text-muted py-4">Todo se mueve bien 👍</li>
                            <?php else: ?>
                                <?php foreach($slowMovingProds as $p): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span class="text-truncate"><?php echo htmlspecialchars($p['nombre']); ?></span>
                                    <span class="badge bg-secondary">Stock: <?php echo $p['cantidad']; ?></span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-tags me-2"></i> Rendimiento por Categorías</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-shopping-basket text-primary me-2"></i> Ventas por Categoría</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($catSalesData)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos</li>
                            <?php else: ?>
                                <?php foreach($catSalesData as $c): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><?php echo htmlspecialchars($c['categoria']); ?></span>
                                    <span class="fw-bold text-dark">$<?php echo number_format($c['total_venta'], 2); ?></span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-chart-pie text-success me-2"></i> Ganancia por Categoría</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($catProfitData)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos</li>
                            <?php else: ?>
                                <?php foreach($catProfitData as $c): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><?php echo htmlspecialchars($c['categoria']); ?></span>
                                    <span class="fw-bold text-success">+$<?php echo number_format($c['total_ganancia'], 2); ?></span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-boxes text-info me-2"></i> Inventario por Categoría</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($invByCategoryData)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos de inventario por categoría</li>
                            <?php else: ?>
                                <?php foreach($invByCategoryData as $c): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><?php echo htmlspecialchars($c['categoria']); ?></span>
                                    <span>
                                        <span class="badge bg-secondary me-2"><?php echo number_format($c['total_cantidad'], 0); ?> un</span>
                                        <span class="fw-bold text-dark">$<?php echo number_format($c['total_costo_valor'], 2); ?></span>
                                    </span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: RESERVAS -->
        <div class="tab-pane fade" id="tab-reservas">
            <div class="card shadow border-0 mb-5">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="m-0 fw-bold text-dark">
                        <i class="fas fa-list-ul me-2 text-primary"></i> Pedidos Web / Reservas
                        <?php if($countPendientes > 0): ?>
                            <span class="badge bg-danger ms-2"><?php echo $countPendientes; ?> Nuevos</span>
                        <?php endif; ?>
                    </h5>
                    <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()"><i class="fas fa-sync-alt"></i></button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-orders align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">#ID</th>
                                    <th>Cliente</th>
                                    <th>Items</th>
                                    <th>Fechas / Reserva</th>
                                    <th>Total</th>
                                    <th>Estado</th>
                                    <th class="text-center">Origen</th>
                                    <th class="text-end pe-3">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($pedidos)): ?>
                                    <tr><td colspan="8" class="text-center py-5 text-muted">No hay pedidos registrados.</td></tr>
                                <?php else: ?>
                                    <?php foreach($pedidos as $row): 
                                        $stmtDet = $pdo->prepare("SELECT d.cantidad, p.nombre FROM pedidos_detalle d JOIN productos p ON d.id_producto = p.codigo WHERE d.id_pedido = ?");
                                        $stmtDet->execute([$row['id']]);
                                        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
                                        $itemsStr = "";
                                        foreach($items as $it) {
                                            $itemsStr .= "<div><small><strong>" . (float)$it['cantidad'] . "x</strong> " . htmlspecialchars($it['nombre']) . "</small></div>";
                                        }

                                        $fechaCrea = strtotime($row['fecha']);
                                        $fechaProg = $row['fecha_programada'] ? strtotime($row['fecha_programada']) : null;
                                        $isFuture = $fechaProg && $fechaProg > time();
                                    ?>
                                    <tr class="<?php echo ($row['estado'] == 'pendiente') ? 'table-warning' : ''; ?>">
                                        <td class="ps-3 fw-bold">#<?php echo $row['id']; ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($row['cliente_nombre']); ?></div>
                                            <div class="small text-muted"><b>Notas: </b> <?php echo htmlspecialchars($row['notas']); ?></div>
                                            <div class="small text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['cliente_direccion']); ?></div>
                                            <div class="small text-primary"><i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($row['cliente_telefono']); ?></div>
                                        </td>
                                        <td><?php echo $itemsStr; ?></td>
                                        <td>
                                            <div class="small text-muted">Creado: <?php echo date('d/m H:i', $fechaCrea); ?></div>
                                            <?php if($fechaProg): ?>
                                                <div class="scheduled-date shadow-sm <?php echo !$isFuture ? 'urgent-date' : ''; ?>">
                                                    <i class="far fa-calendar-alt"></i> Reserva: <?php echo date('d/m h:i A', $fechaProg); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold text-success">$<?php echo number_format($row['total'], 2); ?></td>
                                        <td><?php echo getStatusBadge($row['estado']); ?></td>
                                        <td class="text-center"><?php echo getCanalBadge($row['canal_origen'] ?? 'POS'); ?></td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-sm btn-primary shadow-sm"
                                                    onclick="openManageModal(
                                                        <?php echo $row['id']; ?>,
                                                        '<?php echo $row['estado']; ?>',
                                                        `<?php echo addslashes($row['notas'] ?? ''); ?>`,
                                                        '<?php echo $row['origen']; ?>'
                                                    )">
                                                <i class="fas fa-edit"></i> Gestionar
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: WEB & ECOMMERCE -->
        <div class="tab-pane fade" id="tab-web">
            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-mouse-pointer me-2"></i> Tráfico y Conversión</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card card-stat kpi-orange h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="fw-bold small text-uppercase">Vistas Productos</h6>
                            <h3 class="fw-bold mb-0"><?php echo number_format($totalVistasProductos); ?></h3>
                            <i class="fas fa-eye icon-stat text-dark"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-orange h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="fw-bold small text-uppercase">Clientes Registrados</h6>
                            <h3 class="fw-bold mb-0"><?php echo number_format($usuariosRegistrados); ?></h3>
                            <i class="fas fa-users icon-stat text-dark"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-green h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-white opacity-75 fw-bold small text-uppercase">Visitantes (IPs Únicas)</h6>
                            <h3 class="fw-bold mb-0"><?php echo number_format($ipsUnicas); ?></h3>
                            <i class="fas fa-network-wired icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-green h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-white opacity-75 fw-bold small text-uppercase">Tasa de Conversión</h6>
                            <h3 class="fw-bold mb-0"><?php echo number_format($tasaConversion, 1); ?>%</h3>
                            <i class="fas fa-percentage icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="card card-stat kpi-urls text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-white opacity-75 fw-bold small text-uppercase">Página más popular</h6>
                            <h3 class="fw-bold mb-0"><?php echo htmlspecialchars($urlMasVisitada); ?></h3>
                            <i class="fas fa-link icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100 border-0 border-top border-danger border-4">
                        <div class="card-header bg-white py-3 fw-bold d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-shopping-cart text-danger me-2"></i> Carritos Perdidos</span>
                            <span class="badge bg-danger"><?php echo $carritosAbandonadosCount; ?></span>
                        </div>
                        <div class="card-body d-flex align-items-center justify-content-between py-2">
                            <div>
                                <h2 class="text-danger fw-bold mb-0">$<?php echo number_format($carritosTotalValor, 0); ?></h2>
                                <p class="text-muted small mb-0">Valor potencial no concretado</p>
                            </div>
                            <div class="text-end">
                                <i class="fas fa-ghost fa-3x text-light"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-5">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-globe-americas text-primary me-2"></i> Origen de Visitas</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead class="bg-light"><tr><th class="ps-3">País / IP</th><th class="text-end pe-3">Visitas</th></tr></thead>
                                <tbody>
                                    <?php foreach($topPaises as $p): ?>
                                    <tr>
                                        <td class="ps-3"><span class="badge bg-light text-dark border me-1">🌍</span> <?php echo $p['pais'] ?: 'Local/Desconocido'; ?></td>
                                        <td class="text-end pe-3 fw-bold"><?php echo $p['visitas']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php foreach($topIps as $ip): ?>
                                    <tr class="text-muted">
                                        <td class="ps-3 small"><i class="fas fa-map-marker-alt me-1"></i> <?php echo $ip['ip']; ?></td>
                                        <td class="text-end pe-3"><?php echo $ip['hits']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-fire text-danger me-2"></i> Productos más vistos</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead class="bg-light"><tr><th class="ps-3">Producto</th><th class="text-end pe-3">Vistas</th></tr></thead>
                                <tbody>
                                    <?php if(empty($topVistos)): ?>
                                        <tr><td colspan="2" class="text-center py-4 text-muted">Sin datos aún</td></tr>
                                    <?php else: ?>
                                        <?php foreach($topVistos as $v): ?>
                                        <tr>
                                            <td class="ps-3 text-truncate" style="max-width:150px;"><?php echo htmlspecialchars($v['nombre']); ?></td>
                                            <td class="text-end pe-3 fw-bold text-danger"><?php echo $v['vistas']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 border-top border-warning border-4">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-user-secret text-warning me-2"></i> Seguridad: Ataques</div>
                        <div class="card-body p-2">
                            <pre class="small text-danger bg-light p-2 rounded mb-0" style="font-size: 0.65rem; height: 180px; overflow-y: auto;"><?php echo htmlspecialchars($logAttacks ?: 'Sin amenazas detectadas'); ?></pre>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-network-wired text-primary me-2"></i> Top IPs (Log)</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <tbody>
                                    <?php foreach($logIps as $ip): ?>
                                    <tr><td class="ps-3"><?php echo $ip['value']; ?></td><td class="text-end pe-3 fw-bold"><?php echo $ip['count']; ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-file-alt text-success me-2"></i> Recursos Top</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <tbody>
                                    <?php foreach($logPages as $p): ?>
                                    <tr><td class="ps-3 text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($p['value']); ?></td><td class="text-end pe-3 fw-bold"><?php echo $p['count']; ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-laptop text-info me-2"></i> Browsers/Bots</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <tbody>
                                    <?php foreach($logBrowsers as $b): ?>
                                    <tr><td class="ps-3 text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($b['value']); ?></td><td class="text-end pe-3 fw-bold"><?php echo $b['count']; ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 border-top border-danger border-4 mb-5">
                <div class="card-header bg-white py-3 fw-bold text-danger"><i class="fas fa-exclamation-circle me-2"></i> Errores del Servidor</div>
                <div class="card-body p-2">
                    <pre class="small bg-dark text-light p-3 rounded mb-0" style="font-size: 0.7rem; overflow-x: auto;"><?php echo htmlspecialchars($logErrors ?: 'No hay errores recientes.'); ?></pre>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════════ -->
        <!-- TAB: PROMOCIONES PUSH                                                  -->
        <!-- ═══════════════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tab-promo">
        <div>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-stat h-100 shadow-sm" style="border-left:5px solid #0d6efd!important;background:#eef6ff!important;">
                <div class="card-body">
                    <h6 class="fw-bold small text-uppercase text-primary">Suscriptores Tienda</h6>
                    <h2 class="fw-bold mb-0"><?php echo number_format($pushClientesCount); ?></h2>
                    <p class="text-muted small mb-0">Clientes con push activado en shop.php</p>
                    <i class="fas fa-mobile-alt" style="position:absolute;right:18px;top:18px;font-size:2rem;opacity:.15;color:#0d6efd;"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat h-100 shadow-sm" style="border-left:5px solid #6366f1!important;background:#f0f0ff!important;">
                <div class="card-body">
                    <h6 class="fw-bold small text-uppercase" style="color:#6366f1;">Suscriptores Internos</h6>
                    <h2 class="fw-bold mb-0"><?php echo number_format($pushOperadorCount); ?></h2>
                    <p class="text-muted small mb-0">Operadores y cocina suscritos</p>
                    <i class="fas fa-users-cog" style="position:absolute;right:18px;top:18px;font-size:2rem;opacity:.15;color:#6366f1;"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat h-100 shadow-sm" style="border-left:5px solid #22c55e!important;background:#f0fdf4!important;">
                <div class="card-body">
                    <h6 class="fw-bold small text-uppercase text-success">Campañas Enviadas</h6>
                    <h2 class="fw-bold mb-0"><?php echo number_format($pushTotalEnviadas); ?></h2>
                    <p class="text-muted small mb-0">Notificaciones promocionales en total</p>
                    <i class="fas fa-paper-plane" style="position:absolute;right:18px;top:18px;font-size:2rem;opacity:.15;color:#22c55e;"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- Formulario de composición -->
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-primary text-white fw-bold py-3">
                    <i class="fas fa-bullhorn me-2"></i> Enviar Notificación a Clientes
                </div>
                <div class="card-body">
                    <div id="promoFeedback" class="mb-3" style="display:none;"></div>

                    <!-- Plantillas rápidas -->
                    <div class="mb-3">
                        <label class="form-label fw-bold small">⚡ Plantillas rápidas</label>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="cargarPlantilla('oferta')">🏷️ Oferta especial</button>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="cargarPlantilla('nuevo')">🆕 Nuevo producto</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="cargarPlantilla('urgente')">⏰ Urgente / última hora</button>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="cargarPlantilla('descuento')">💰 Descuento</button>
                        </div>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Título <span class="text-danger">*</span></label>
                        <input type="text" id="promoTitulo" class="form-control" maxlength="80"
                               placeholder="Ej: 🏷️ Oferta especial este fin de semana">
                        <div class="form-text"><span id="promoTituloCount">0</span>/80 caracteres</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Mensaje</label>
                        <textarea id="promoCuerpo" class="form-control" rows="3" maxlength="200"
                                  placeholder="Ej: Hasta 30% de descuento en productos seleccionados. ¡Solo hoy!"></textarea>
                        <div class="form-text"><span id="promoCuerpoCount">0</span>/200 caracteres</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">URL de destino</label>
                        <input type="text" id="promoUrl" class="form-control" value="/marinero/shop.php"
                               placeholder="/marinero/shop.php">
                        <div class="form-text">Al tocar la notificación se abre esta URL.</div>
                    </div>

                    <!-- Vista previa -->
                    <div class="mb-3 p-3 rounded" style="background:#f8f9fa;border:1px dashed #dee2e6;">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <img src="icon-192.png" width="24" height="24" class="rounded" alt="">
                            <strong class="small" id="previewTitulo" style="color:#333;">Vista previa del título</strong>
                        </div>
                        <p class="small text-muted mb-0" id="previewCuerpo">El mensaje aparecerá aquí...</p>
                    </div>

                    <button type="button" class="btn btn-primary w-100 fw-bold py-2" id="btnEnviarPromo" onclick="enviarPromo()">
                        <i class="fas fa-paper-plane me-2"></i> Enviar a <?php echo number_format($pushClientesCount); ?> suscriptores
                    </button>

                    <?php if ($pushClientesCount === 0): ?>
                    <div class="alert alert-warning mt-3 small py-2">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        No hay suscriptores de tienda todavía. Los clientes deben activar las notificaciones en <a href="shop.php" target="_blank">shop.php</a>.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Historial de campañas -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-history text-secondary me-2"></i> Historial de Campañas</span>
                    <span class="badge bg-secondary"><?php echo count($pushHistorialPromo); ?> recientes</span>
                </div>
                <div class="card-body p-0" style="max-height:520px;overflow-y:auto;">
                    <?php if (empty($pushHistorialPromo)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                        <p>Aún no se han enviado campañas promocionales.</p>
                    </div>
                    <?php else: ?>
                    <table class="table table-hover mb-0 small">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3" style="width:55%;">Mensaje</th>
                                <th>Fecha y Hora</th>
                                <th class="text-center">URL</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pushHistorialPromo as $pn): ?>
                        <tr>
                            <td class="ps-3">
                                <div class="fw-semibold"><?php echo htmlspecialchars($pn['titulo']); ?></div>
                                <?php if (!empty($pn['cuerpo'])): ?>
                                <div class="text-muted" style="font-size:.75rem;"><?php echo htmlspecialchars(mb_substr($pn['cuerpo'], 0, 80)); ?><?php echo strlen($pn['cuerpo']) > 80 ? '…' : ''; ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted" style="white-space:nowrap;">
                                <?php echo date('d/m/y H:i', strtotime($pn['created_at'])); ?>
                            </td>
                            <td class="text-center">
                                <?php if (!empty($pn['url'])): ?>
                                <a href="<?php echo htmlspecialchars($pn['url']); ?>" target="_blank"
                                   class="text-primary" title="<?php echo htmlspecialchars($pn['url']); ?>">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        </div><!-- /row -->
        </div><!-- /inner -->
        </div><!-- /tab-promo -->
        <!-- TAB AUDITORÍA ─────────────────────────────────────────────────────
             Audit trail inmutable: toda anulación, descuento y devolución
             queda firmada con cajero + IP + SHA1 checksum. -->
        <div class="tab-pane fade" id="tab-auditoria">

            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1">
                <i class="fas fa-shield-alt me-2 text-danger"></i>
                Audit Trail — Eventos Firmados
                <small class="text-muted fw-normal ms-2" style="font-size:.7rem;text-transform:none;">
                    <?php echo $fechaInicio; ?> → <?php echo $fechaFin; ?> · <?php echo $auditKpi['total']; ?> registros
                </small>
            </h6>

            <!-- KPIs del período -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card card-stat kpi-critico kpi-audit-anuladas h-100 shadow-sm">
                        <div class="card-body py-3">
                            <h6 class="text-muted fw-bold small text-uppercase mb-1">Anulaciones</h6>
                            <h3 class="fw-bold mb-0"><?php echo $auditKpi['anulaciones']; ?></h3>
                            <i class="fas fa-ban icon-stat"></i>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-stat kpi-audit-descuentos h-100 shadow-sm">
                        <div class="card-body py-3">
                            <h6 class="text-muted fw-bold small text-uppercase mb-1">Descuentos</h6>
                            <h3 class="fw-bold mb-0"><?php echo $auditKpi['descuentos']; ?></h3>
                            <i class="fas fa-tag icon-stat" style="color:#f59e0b"></i>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-stat kpi-audit-devol h-100 shadow-sm">
                        <div class="card-body py-3">
                            <h6 class="text-muted fw-bold small text-uppercase mb-1">Devoluciones</h6>
                            <h3 class="fw-bold mb-0"><?php echo $auditKpi['devoluciones']; ?></h3>
                            <i class="fas fa-undo icon-stat" style="color:#6f42c1"></i>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-stat kpi-audit-integ h-100 shadow-sm">
                        <div class="card-body py-3">
                            <h6 class="text-muted fw-bold small text-uppercase mb-1">Integridad SHA1</h6>
                            <?php if ($auditKpi['integridad_err'] > 0): ?>
                                <h3 class="fw-bold mb-0 text-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?php echo $auditKpi['integridad_err']; ?> FALLO<?php echo $auditKpi['integridad_err'] > 1 ? 'S' : ''; ?>
                                </h3>
                            <?php else: ?>
                                <h3 class="fw-bold mb-0 text-success">
                                    <i class="fas fa-check-circle"></i>
                                    <?php echo $auditKpi['integridad_ok']; ?> OK
                                </h3>
                            <?php endif; ?>
                            <i class="fas fa-fingerprint icon-stat" style="color:#198754"></i>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($auditKpi['integridad_err'] > 0): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3">
                <i class="fas fa-exclamation-circle fs-5"></i>
                <div><strong>¡Alerta de integridad!</strong> <?php echo $auditKpi['integridad_err']; ?> registro(s) tienen checksum SHA1 incorrecto. Posible manipulación directa de la base de datos.</div>
            </div>
            <?php endif; ?>

            <!-- Barra de filtros client-side -->
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body py-2 audit-filter-bar">
                    <div class="row g-2 align-items-center">
                        <div class="col-auto">
                            <select id="auditFilterType" class="form-select form-select-sm" onchange="auditFilter()">
                                <option value="">— Todos los tipos —</option>
                                <option value="VENTA_ANULADA">Anulaciones</option>
                                <option value="DESCUENTO">Descuentos (Item + Global)</option>
                                <option value="DEVOLUCION">Devoluciones</option>
                                <option value="VENTA_GUARDADA">Ventas guardadas</option>
                                <option value="SESION">Apertura / Cierre sesión</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <select id="auditFilterUser" class="form-select form-select-sm" onchange="auditFilter()">
                                <option value="">— Todos los cajeros —</option>
                                <?php
                                $usuarios = array_unique(array_column($auditRows, 'usuario'));
                                sort($usuarios);
                                foreach ($usuarios as $u): ?>
                                    <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <input type="text" id="auditSearch" class="form-control form-control-sm" placeholder="Buscar en datos (motivo, producto, ticket...)" oninput="auditFilter()">
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()" title="Actualizar">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                        <div class="col-auto">
                            <span class="text-muted small" id="auditCount"><?php echo count($auditRows); ?> eventos</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de eventos -->
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
                        <table class="table table-hover mb-0 audit-table" id="auditTable">
                            <thead class="table-dark sticky-top" style="font-size:.75rem;">
                                <tr>
                                    <th style="width:45px">#</th>
                                    <th style="width:130px">Fecha / Hora</th>
                                    <th style="width:130px">Tipo</th>
                                    <th style="width:120px">Cajero / Admin</th>
                                    <th>Detalle</th>
                                    <th style="width:80px">IP</th>
                                    <th style="width:45px" title="Integridad SHA1">🔒</th>
                                </tr>
                            </thead>
                            <tbody id="auditTableBody">
                            <?php if (empty($auditRows)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-shield-alt fa-2x mb-2 d-block opacity-25"></i>
                                        Sin eventos de auditoría en este período.<br>
                                        <small>Los eventos aparecen en tiempo real al registrar ventas, anulaciones y descuentos.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                            <?php foreach ($auditRows as $r):
                                $accion  = $r['accion'];
                                $datos   = json_decode($r['datos'] ?? '{}', true) ?? [];
                                $ts      = $r['created_at'] ?? 'Sin fecha';
                                $ipAddr  = $r['ip'] ? htmlspecialchars($r['ip']) : '<span class="text-muted">—</span>';

                                // Clase de fila por tipo
                                $rowClass = 'audit-other';
                                if ($accion === 'VENTA_ANULADA')  $rowClass = 'audit-anulada';
                                elseif (str_starts_with($accion, 'DESCUENTO'))  $rowClass = 'audit-descuento';
                                elseif (str_starts_with($accion, 'DEVOLUCION')) $rowClass = 'audit-devolucion';
                                elseif (str_starts_with($accion, 'SESION'))     $rowClass = 'audit-sesion';
                                elseif ($accion === 'VENTA_GUARDADA')            $rowClass = 'audit-venta';

                                // Verificar integridad
                                $ip_raw   = $r['ip'] ?? '';
                                $esperado = sha1($accion . '|' . $r['usuario'] . '|' . ($r['datos'] ?? '') . '|' . $ip_raw);
                                if (!$r['checksum']) {
                                    $intHtml = '<span class="audit-integrity-na" title="Sin checksum (registro antiguo)">—</span>';
                                } elseif ($r['checksum'] === $esperado) {
                                    $intHtml = '<span class="audit-integrity-ok" title="SHA1 verificado">✓</span>';
                                } else {
                                    $intHtml = '<span class="audit-integrity-err" title="¡Checksum no coincide! Posible manipulación">✗ !</span>';
                                }

                                // Datos legibles para búsqueda JS
                                $dataBusq = strtolower(json_encode($datos, JSON_UNESCAPED_UNICODE) . ' ' . $r['usuario'] . ' ' . $accion);
                            ?>
                            <tr class="<?php echo $rowClass; ?>"
                                data-accion="<?php echo htmlspecialchars($accion); ?>"
                                data-usuario="<?php echo htmlspecialchars(strtolower($r['usuario'])); ?>"
                                data-busq="<?php echo htmlspecialchars($dataBusq); ?>">
                                <td class="text-muted" style="font-size:.7rem"><?php echo $r['id']; ?></td>
                                <td style="font-size:.72rem;white-space:nowrap">
                                    <?php echo $ts !== 'Sin fecha' ? date('d/m H:i:s', strtotime($ts)) : '<span class="text-muted">—</span>'; ?>
                                </td>
                                <td><?php echo auditBadgeHtml($accion); ?></td>
                                <td class="fw-bold" style="font-size:.78rem"><?php echo htmlspecialchars($r['usuario']); ?></td>
                                <td style="font-size:.78rem"><?php echo auditResumen($accion, $datos); ?></td>
                                <td style="font-size:.65rem;color:#94a3b8"><?php echo $ipAddr; ?></td>
                                <td class="text-center"><?php echo $intHtml; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light py-1 text-muted" style="font-size:.7rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    Tabla de solo-lectura · SHA1 por fila · Mostrando máx. 500 eventos por período ·
                    <strong class="text-danger">Nunca</strong> se ejecutan UPDATE/DELETE sobre esta tabla desde la aplicación.
                </div>
            </div>

        </div><!-- /tab-auditoria -->

    </div><!-- /tab-content -->
</div><!-- /container-fluid -->

<div class="modal fade" id="manageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">Gestionar Pedido #<span id="modalOrderId"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="inputOrderId">
                <input type="hidden" id="inputOrderOrigen">
                <div class="mb-4">
                    <label class="form-label fw-bold">Estado del Pedido</label>
                    <select class="form-select form-select-lg" id="inputOrderState">
                        <option value="pendiente">🟡 Pendiente (Recibido)</option>
                        <option value="proceso">🔵 En Cocina / Preparación</option>
                        <option value="camino">🛵 En Camino / Listo</option>
                        <option value="completado">🟢 Completado (Entregado)</option>
                        <option value="cancelado">🔴 Cancelado</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Nota para el Cliente</label>
                    <div class="alert alert-info py-2 small"><i class="fas fa-info-circle"></i> Visible en el rastreador.</div>
                    <textarea class="form-control" id="inputOrderNote" rows="3" placeholder="Ej: Motorista Juan en camino..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success fw-bold" onclick="saveChanges()">Guardar</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // Configuración del Gráfico de Pastel
    const ctxPagos = document.getElementById('chartPagos').getContext('2d');
    const pagosLabels = <?php echo json_encode(array_column($pagosData, 'metodo')); ?>;
    const pagosValues = <?php echo json_encode(array_column($pagosData, 'total')); ?>;
    
    // Convertir valores a números para asegurar compatibilidad con ChartJS
    const pagosValuesNum = pagosValues.map(Number);

    new Chart(ctxPagos, {
        type: 'doughnut',
        data: {
            labels: pagosLabels,
            datasets: [{
                data: pagosValuesNum,
                backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'right' } },
            cutout: '70%'
        }
    });

    const manageModal = new bootstrap.Modal(document.getElementById('manageModal'));

    function openManageModal(id, estado, nota, origen) {
        document.getElementById('inputOrderId').value = id;
        document.getElementById('inputOrderOrigen').value = origen || 'POS';
        document.getElementById('modalOrderId').innerText = id;
        document.getElementById('inputOrderState').value = estado;
        document.getElementById('inputOrderNote').value = nota;
        manageModal.show();
    }

    async function saveChanges() {
        const id = document.getElementById('inputOrderId').value;
        const estado = document.getElementById('inputOrderState').value;
        const nota = document.getElementById('inputOrderNote').value;
        const origen = document.getElementById('inputOrderOrigen').value;
        
        const btn = event.currentTarget;
        const oldHtml = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = 'Guardando...';

        try {
            const resp = await fetch('update_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, estado, nota, origen })
            });
            const result = await resp.json();
            
            if(result.status === 'success') {
                location.reload(); 
            } else {
                alert('Error: ' + (result.msg || 'Desconocido'));
                btn.disabled = false; btn.innerHTML = oldHtml;
            }
        } catch (error) {
            alert('Error de conexión');
            btn.disabled = false; btn.innerHTML = oldHtml;
        }
    }

    setInterval(() => {
        if(!document.querySelector('.modal.show')) location.reload();
    }, 60000);

    // ── TAB PROMOCIONES PUSH ───────────────────────────────────────────────────

    // Plantillas rápidas
    const PROMO_PLANTILLAS = {
        oferta:   { titulo: '🏷️ Oferta especial hoy', cuerpo: 'Tenemos productos seleccionados con precios especiales. ¡Visítanos ahora!' },
        nuevo:    { titulo: '🆕 Nuevo producto disponible', cuerpo: 'Acabamos de incorporar novedades a nuestro catálogo. ¡Échale un vistazo!' },
        urgente:  { titulo: '⏰ ¡Últimas horas de la oferta!', cuerpo: 'La promoción termina pronto. No te quedes sin tu pedido.' },
        descuento:{ titulo: '💰 Descuento especial para ti', cuerpo: 'Aprovecha esta oportunidad única con precios rebajados en nuestra tienda.' },
    };

    function cargarPlantilla(tipo) {
        const p = PROMO_PLANTILLAS[tipo];
        if (!p) return;
        document.getElementById('promoTitulo').value = p.titulo;
        document.getElementById('promoCuerpo').value = p.cuerpo;
        actualizarPreviewPromo();
        actualizarContadoresPromo();
    }

    function actualizarPreviewPromo() {
        const titulo = document.getElementById('promoTitulo').value || 'Vista previa del título';
        const cuerpo = document.getElementById('promoCuerpo').value || 'El mensaje aparecerá aquí...';
        document.getElementById('previewTitulo').textContent = titulo;
        document.getElementById('previewCuerpo').textContent = cuerpo;
    }

    function actualizarContadoresPromo() {
        const t = document.getElementById('promoTitulo');
        const c = document.getElementById('promoCuerpo');
        if (t) document.getElementById('promoTituloCount').textContent = t.value.length;
        if (c) document.getElementById('promoCuerpoCount').textContent = c.value.length;
    }

    document.addEventListener('DOMContentLoaded', () => {
        const t = document.getElementById('promoTitulo');
        const c = document.getElementById('promoCuerpo');
        if (t) { t.addEventListener('input', () => { actualizarPreviewPromo(); actualizarContadoresPromo(); }); }
        if (c) { c.addEventListener('input', () => { actualizarPreviewPromo(); actualizarContadoresPromo(); }); }
    });

    async function enviarPromo() {
        const titulo  = (document.getElementById('promoTitulo').value || '').trim();
        const cuerpo  = (document.getElementById('promoCuerpo').value || '').trim();
        const url     = (document.getElementById('promoUrl').value     || '/marinero/shop.php').trim();
        const feedback = document.getElementById('promoFeedback');
        const btn     = document.getElementById('btnEnviarPromo');

        if (!titulo) {
            feedback.innerHTML = '<div class="alert alert-danger py-2 small"><i class="fas fa-exclamation-triangle me-1"></i> El título es obligatorio.</div>';
            feedback.style.display = 'block';
            return;
        }

        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Enviando...';
        feedback.style.display = 'none';

        try {
            const resp = await fetch('push_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'send', tipo: 'cliente', titulo, cuerpo, url })
            });
            const data = await resp.json();

            if (data.status === 'ok') {
                feedback.innerHTML = `<div class="alert alert-success py-2 small"><i class="fas fa-check-circle me-1"></i> ${data.msg || 'Enviado correctamente'}</div>`;
                document.getElementById('promoTitulo').value = '';
                document.getElementById('promoCuerpo').value = '';
                actualizarPreviewPromo(); actualizarContadoresPromo();
                // Recargar historial tras 2s
                setTimeout(() => location.reload(), 2500);
            } else {
                feedback.innerHTML = `<div class="alert alert-danger py-2 small"><i class="fas fa-times-circle me-1"></i> ${data.error || 'Error desconocido'}</div>`;
            }
        } catch (e) {
            feedback.innerHTML = '<div class="alert alert-danger py-2 small"><i class="fas fa-wifi me-1"></i> Error de conexión.</div>';
        }

        feedback.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = oldHtml;
    }
</script>



<script>
// ── Filtro client-side del tab Auditoría ─────────────────────────────────────
function auditFilter() {
    const tipo    = document.getElementById('auditFilterType')?.value  || '';
    const usuario = (document.getElementById('auditFilterUser')?.value || '').toLowerCase();
    const busq    = (document.getElementById('auditSearch')?.value     || '').toLowerCase();

    const rows = document.querySelectorAll('#auditTableBody tr[data-accion]');
    let visibles = 0;

    rows.forEach(tr => {
        const accion  = tr.dataset.accion  || '';
        const usr     = tr.dataset.usuario || '';
        const dataBusq= tr.dataset.busq    || '';

        let ok = true;

        // Filtro por tipo
        if (tipo) {
            if (tipo === 'DESCUENTO' && !accion.startsWith('DESCUENTO')) ok = false;
            else if (tipo === 'DEVOLUCION' && !accion.startsWith('DEVOLUCION')) ok = false;
            else if (tipo === 'SESION' && !accion.startsWith('SESION')) ok = false;
            else if (tipo !== 'DESCUENTO' && tipo !== 'DEVOLUCION' && tipo !== 'SESION' && accion !== tipo) ok = false;
        }

        // Filtro por usuario
        if (usuario && !usr.includes(usuario)) ok = false;

        // Búsqueda libre en datos
        if (busq && !dataBusq.includes(busq)) ok = false;

        tr.style.display = ok ? '' : 'none';
        if (ok) visibles++;
    });

    const countEl = document.getElementById('auditCount');
    if (countEl) countEl.textContent = visibles + ' eventos';
}

    // ── TAB TIEMPO REAL ────────────────────────────────────────────────────────
    let rtChartHoras = null;
    let rtChartPagos = null;
    let rtInterval   = null;

    document.getElementById('btnTabRealtime').addEventListener('shown.bs.tab', function() {
        cargarRT();
        rtInterval = setInterval(cargarRT, 30000);
    });
    document.getElementById('btnTabRealtime').addEventListener('hidden.bs.tab', function() {
        clearInterval(rtInterval);
        rtInterval = null;
    });

    async function cargarRT() {
        try {
            const r = await fetch('dashboard_rt_api.php');
            if (!r.ok) return;
            const d = await r.json();
            renderRT(d);
        } catch(e) { console.warn('RT fetch error:', e); }
    }

    function renderRT(d) {
        const fmt  = v => '$' + Number(v).toLocaleString('es', {minimumFractionDigits:0, maximumFractionDigits:0});
        const pct  = (a, b) => b > 0 ? ((a - b) / b * 100).toFixed(1) : (a > 0 ? '+∞' : '0');
        const cPct = v => parseFloat(v) >= 0 ? 'text-success' : 'text-danger';
        const icoP = v => parseFloat(v) >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';

        // KPIs
        document.getElementById('rt-venta').textContent    = fmt(d.hoy.total);
        document.getElementById('rt-ganancia').textContent = fmt(d.ganancia_hoy);
        document.getElementById('rt-ops').textContent      = d.hoy.ops;
        document.getElementById('rt-ticket').textContent   = d.hoy.ops > 0 ? fmt(d.hoy.total / d.hoy.ops) : '$0';
        document.getElementById('rt-mensajerias').textContent       = d.mensajerias;
        document.getElementById('rt-mensajerias-total').textContent = fmt(d.total_mensajeria);
        document.getElementById('rt-reservas').textContent          = d.reservas;
        document.getElementById('rt-reservas-total').textContent    = fmt(d.total_reservas);
        document.getElementById('rt-autoservicio').textContent      = d.autoservicio;
        document.getElementById('rt-autoservicio-total').textContent= fmt(d.total_autoservicio);

        // Comparativas
        const pAyer = pct(d.hoy.total, d.ayer.total);
        const pSem  = pct(d.hoy.total, d.semana_pasada.total);
        const ayerEl = document.getElementById('rt-vs-ayer');
        const semEl  = document.getElementById('rt-vs-sem');
        ayerEl.innerHTML = `<i class="fas ${icoP(pAyer)} me-1"></i>${pAyer}% vs ayer`;
        ayerEl.className = `small mt-1 fw-bold ${cPct(pAyer)}`;
        semEl.innerHTML  = `<i class="fas ${icoP(pSem)} me-1"></i>${pSem}% vs sem. ant.`;
        semEl.className  = `small mt-1 fw-bold ${cPct(pSem)}`;

        // Canales
        const cm = {};
        d.canales_hoy.forEach(c => cm[c.canal] = c);
        document.getElementById('rt-canal-pos').textContent = (cm['POS']?.cnt       || 0) + ' ops';
        document.getElementById('rt-canal-web').textContent = (cm['Web']?.cnt       || 0) + ' ops';
        document.getElementById('rt-canal-wa').textContent  = (cm['WhatsApp']?.cnt  || 0) + ' ops';

        // Cocina
        document.getElementById('rt-cocina-p').textContent = d.cocina_pendiente;
        document.getElementById('rt-cocina-e').textContent = d.cocina_elaboracion;
        document.getElementById('rt-cocina-t').textContent = d.cocina_terminado;

        // Cajas activas
        const cajasEl = document.getElementById('rt-cajas');
        cajasEl.innerHTML = d.cajas_abiertas.length
            ? d.cajas_abiertas.map(c => `
                <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                    <div><i class="fas fa-cash-register text-success me-2"></i><strong>${c.nombre_cajero}</strong></div>
                    <div class="text-muted">${fmt(c.monto_inicial)} · desde ${String(c.fecha_apertura).slice(11,16)}</div>
                </div>`).join('')
            : '<div class="text-muted text-center py-2 small">Sin caja abierta</div>';

        // Última venta
        if (d.ultima_venta) {
            document.getElementById('rt-ultima-venta').innerHTML =
                `<span class="badge bg-light text-dark border me-1">${String(d.ultima_venta.fecha).slice(11,16)}</span>`
                + `<strong>${fmt(d.ultima_venta.total)}</strong>`
                + ` <span class="text-muted mx-1">·</span> ${d.ultima_venta.metodo_pago || 'Efectivo'}`
                + ` <span class="text-muted mx-1">·</span> ${d.ultima_venta.tipo_servicio || ''}`
                + ` <span class="text-muted mx-1">·</span> ${d.ultima_venta.cliente_nombre || ''}`;
        }

        // Top 10
        document.getElementById('rt-top10').innerHTML = d.top10.length
            ? d.top10.map((p, i) => `
                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <div><span class="badge bg-light text-dark border me-2">#${i+1}</span>${p.nombre}</div>
                    <div>
                        <span class="badge bg-primary rounded-pill me-1">${p.vendidos} un</span>
                        <small class="text-muted">${fmt(p.total_venta)}</small>
                    </div>
                </li>`).join('')
            : '<li class="list-group-item text-center text-muted">Sin ventas aún</li>';

        // Chart ventas por hora
        const hLabels = Array.from({length:24}, (_,i) => i+'h');
        if (!rtChartHoras) {
            rtChartHoras = new Chart(document.getElementById('rt-chart-horas').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: hLabels,
                    datasets: [
                        { label: 'Hoy', data: d.ventas_por_hora, backgroundColor: 'rgba(79,70,229,0.75)', borderRadius: 4, order: 2 },
                        { label: 'Ayer', data: d.ayer_por_hora, type: 'line', borderColor: '#f59e0b', backgroundColor: 'transparent', borderWidth: 2, pointRadius: 2, tension: 0.3, order: 1 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } } },
                    scales: { y: { beginAtZero: true, ticks: { callback: v => '$'+Number(v).toLocaleString(), font: { size: 10 } } }, x: { ticks: { font: { size: 10 } } } }
                }
            });
        } else {
            rtChartHoras.data.datasets[0].data = d.ventas_por_hora;
            rtChartHoras.data.datasets[1].data = d.ayer_por_hora;
            rtChartHoras.update('none');
        }

        // Chart pagos donut
        const pLabels = d.pagos_hoy.map(p => p.metodo);
        const pValues = d.pagos_hoy.map(p => parseFloat(p.total));
        const pColors = ['#4f46e5','#10b981','#f59e0b','#06b6d4','#ef4444','#8b5cf6','#f43f5e'];
        if (!rtChartPagos) {
            rtChartPagos = new Chart(document.getElementById('rt-chart-pagos').getContext('2d'), {
                type: 'doughnut',
                data: { labels: pLabels, datasets: [{ data: pValues, backgroundColor: pColors, borderWidth: 0 }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } },
                    cutout: '68%'
                }
            });
        } else {
            rtChartPagos.data.labels = pLabels;
            rtChartPagos.data.datasets[0].data = pValues;
            rtChartPagos.update('none');
        }

        // Tabla métodos de pago
        document.getElementById('rt-pagos-tabla').innerHTML = d.pagos_hoy.map(p => `
            <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                <span>${p.metodo}</span>
                <div><span class="badge bg-secondary me-2">${p.cnt} ops</span><strong>${fmt(p.total)}</strong></div>
            </li>`).join('') || '<li class="list-group-item text-center text-muted">Sin pagos aún</li>';

        document.getElementById('rt-timestamp').textContent = 'Actualizado ' + d.ts;
    }
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

