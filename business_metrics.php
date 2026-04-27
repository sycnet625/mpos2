<?php
/**
 * business_metrics.php — Motor Central de Cálculo de Negocio
 *
 * Uso:
 *   require_once 'business_metrics.php';
 *   $m = bm_calcular($pdo, [
 *       'fecha_inicio'  => '2026-01-01',
 *       'fecha_fin'     => '2026-01-31',
 *       'id_empresa'    => 1,
 *       'id_sucursal'   => 1,      // null = todas las sucursales de la empresa
 *       'id_almacen'    => 1,      // null = todos los almacenes
 *       'secciones'     => 'all',  // o array ['ventas','productos',...]
 *       'limite_top'    => 10,
 *   ]);
 */

require_once __DIR__ . '/accounting_helpers.php';

// ─── Constantes de sección ────────────────────────────────────────────────────
if (!defined('BM_VENTAS'))     define('BM_VENTAS',     'ventas');
if (!defined('BM_GASTOS'))     define('BM_GASTOS',     'gastos');
if (!defined('BM_PRODUCTOS'))  define('BM_PRODUCTOS',  'productos');
if (!defined('BM_CLIENTES'))   define('BM_CLIENTES',   'clientes');
if (!defined('BM_CAJERO'))     define('BM_CAJERO',     'cajero');
if (!defined('BM_SERIES'))     define('BM_SERIES',     'series_diarias');
if (!defined('BM_INVENTARIO')) define('BM_INVENTARIO', 'inventario');
if (!defined('BM_PEDIDOS'))    define('BM_PEDIDOS',    'pedidos');

// ─── Punto de entrada público ─────────────────────────────────────────────────
if (!function_exists('bm_calcular')) {
    function bm_calcular(PDO $pdo, array $params): array
    {
        $p = _bm_normalize_params($params);

        $secciones = $p['secciones'];
        $reqAll    = in_array('all', $secciones, true);

        $needsTickets = $reqAll
            || in_array(BM_VENTAS,    $secciones, true)
            || in_array(BM_PRODUCTOS, $secciones, true)
            || in_array(BM_CLIENTES,  $secciones, true)
            || in_array(BM_CAJERO,    $secciones, true);

        $tickets           = [];
        $detalles          = [];
        $detallesPorTicket = [];

        if ($needsTickets) {
            [$tickets, $detalles, $detallesPorTicket] = _bm_load_tickets_detalles($pdo, $p);
        }

        $result = ['params' => $p];

        if ($reqAll || in_array(BM_VENTAS,     $secciones, true)) {
            $result[BM_VENTAS]     = _bm_calc_ventas($pdo, $p, $tickets, $detallesPorTicket);
        }
        if ($reqAll || in_array(BM_GASTOS,     $secciones, true)) {
            $result[BM_GASTOS]     = _bm_calc_gastos($pdo, $p);
        }
        if ($reqAll || in_array(BM_PRODUCTOS,  $secciones, true)) {
            $result[BM_PRODUCTOS]  = _bm_calc_productos($detalles, $p['limite_top']);
        }
        if ($reqAll || in_array(BM_CLIENTES,   $secciones, true)) {
            $result[BM_CLIENTES]   = _bm_calc_clientes($tickets, $p['limite_top']);
        }
        if ($reqAll || in_array(BM_CAJERO,     $secciones, true)) {
            $result[BM_CAJERO]     = _bm_calc_cajero($pdo, $p, $tickets);
        }
        if ($reqAll || in_array(BM_SERIES,     $secciones, true)) {
            $result[BM_SERIES]     = _bm_calc_series($pdo, $p);
        }
        if ($reqAll || in_array(BM_INVENTARIO, $secciones, true)) {
            $result[BM_INVENTARIO] = _bm_calc_inventario($pdo, $p);
        }
        if ($reqAll || in_array(BM_PEDIDOS,    $secciones, true)) {
            $result[BM_PEDIDOS]    = _bm_calc_pedidos($pdo, $p);
        }

        // Cross-section: ganancia_neta y margen_neto_pct
        if (isset($result[BM_VENTAS], $result[BM_GASTOS])) {
            $gBruta  = $result[BM_VENTAS]['ganancia_bruta'];
            $gNeta   = $gBruta - $result[BM_GASTOS]['total'] - $result[BM_GASTOS]['mermas'];
            $vTotal  = $result[BM_VENTAS]['total'];
            $result[BM_GASTOS]['ganancia_neta']   = $gNeta;
            $result[BM_GASTOS]['margen_neto_pct'] = $vTotal > 0 ? ($gNeta / $vTotal) * 100 : 0.0;
        }

        return $result;
    }
}

// ─── Normalización de parámetros ──────────────────────────────────────────────
if (!function_exists('_bm_normalize_params')) {
    function _bm_normalize_params(array $p): array
    {
        if (empty($p['fecha_inicio']) || empty($p['fecha_fin']) || empty($p['id_empresa'])) {
            throw new InvalidArgumentException(
                'bm_calcular requiere fecha_inicio, fecha_fin e id_empresa'
            );
        }

        $secciones = $p['secciones'] ?? 'all';
        if ($secciones === 'all') {
            $secciones = ['all'];
        } elseif (!is_array($secciones)) {
            $secciones = [$secciones];
        }

        return [
            'fecha_inicio'  => (string)$p['fecha_inicio'],
            'fecha_fin'     => (string)$p['fecha_fin'],
            'id_empresa'    => (int)$p['id_empresa'],
            'id_sucursal'   => isset($p['id_sucursal'])  ? (int)$p['id_sucursal']  : null,
            'id_almacen'    => isset($p['id_almacen'])   ? (int)$p['id_almacen']   : null,
            'secciones'     => $secciones,
            'limite_top'    => max(1, (int)($p['limite_top'] ?? 10)),
        ];
    }
}

// ─── Construcción dinámica de cláusula WHERE para ventas ─────────────────────
/**
 * Devuelve [$whereStr, $binds] para queries sobre ventas_cabecera (alias $alias)
 * con LEFT JOIN caja_sesiones cs ya presente.
 * Incluye: rango de fechas contables, id_empresa, id_sucursal (si aplica),
 *          y ventas_reales_where_clause().
 */
if (!function_exists('_bm_build_where')) {
    function _bm_build_where(array $p, string $alias = 'v'): array
    {
        $a     = $alias;
        $where = "IFNULL(cs.fecha_contable, DATE({$a}.fecha)) BETWEEN ? AND ?";
        $binds = [$p['fecha_inicio'], $p['fecha_fin']];

        $where .= " AND {$a}.id_empresa = ?";
        $binds[] = $p['id_empresa'];

        if ($p['id_sucursal'] !== null) {
            $where .= " AND {$a}.id_sucursal = ?";
            $binds[] = $p['id_sucursal'];
        }

        $where .= ' AND ' . ventas_reales_where_clause($alias);

        return [$where, $binds];
    }
}

// ─── Carga compartida de tickets y detalles ───────────────────────────────────
if (!function_exists('_bm_load_tickets_detalles')) {
    function _bm_load_tickets_detalles(PDO $pdo, array $p): array
    {
        [$where, $binds] = _bm_build_where($p);

        $sql = "SELECT v.*,
                       IFNULL(cs.fecha_contable, DATE(v.fecha)) AS fecha_contable_calc
                FROM ventas_cabecera v
                LEFT JOIN caja_sesiones cs
                       ON (v.id_caja = cs.id OR v.id_sesion_caja = cs.id)
                WHERE $where
                ORDER BY v.fecha ASC, v.id ASC";

        $st = $pdo->prepare($sql);
        $st->execute($binds);
        $tickets = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($tickets)) {
            return [[], [], []];
        }

        $ids = array_column($tickets, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $st = $pdo->prepare(
            "SELECT vd.*,
                    p.nombre        AS nombre_prod,
                    p.categoria,
                    p.costo         AS costo_unitario,
                    p.es_elaborado  AS es_elaborado
             FROM ventas_detalle vd
             JOIN productos p ON vd.id_producto = p.codigo
             WHERE vd.id_venta_cabecera IN ($ph)
             ORDER BY vd.id_venta_cabecera ASC, vd.id ASC"
        );
        $st->execute($ids);
        $detalles = $st->fetchAll(PDO::FETCH_ASSOC);

        $detallesPorTicket = [];
        foreach ($detalles as $d) {
            $detallesPorTicket[(int)$d['id_venta_cabecera']][] = $d;
        }

        return [$tickets, $detalles, $detallesPorTicket];
    }
}

// ─── BM_VENTAS ────────────────────────────────────────────────────────────────
if (!function_exists('_bm_calc_ventas')) {
    function _bm_calc_ventas(PDO $pdo, array $p, array $tickets, array $detallesPorTicket): array
    {
        $total           = 0.0;
        $costo           = 0.0;
        $countTickets    = 0;
        $countDevol      = 0;
        $valorDevol      = 0.0;
        $metodosPago     = [];
        $porCanal        = [];
        $porTipoServicio = [];
        $fechas          = [];

        // Carga métodos de pago reales desde ventas_pagos
        $pagosMap = [];  // ticket_id => [metodo => monto]
        if (!empty($tickets)) {
            $ids = array_column($tickets, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            try {
                $st = $pdo->prepare(
                    "SELECT id_venta_cabecera, metodo_pago, monto
                     FROM ventas_pagos
                     WHERE id_venta_cabecera IN ($ph)"
                );
                $st->execute($ids);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $tid = (int)$row['id_venta_cabecera'];
                    $mp  = (string)($row['metodo_pago'] ?: 'Efectivo');
                    $pagosMap[$tid][$mp] = ($pagosMap[$tid][$mp] ?? 0.0) + floatval($row['monto']);
                }
            } catch (Throwable $e) {
                // ventas_pagos puede no existir en instalaciones antiguas
            }
        }

        foreach ($tickets as $t) {
            $tid   = (int)$t['id'];
            $tval  = floatval($t['total'] ?? 0);
            $fecha = substr((string)($t['fecha_contable_calc'] ?? $t['fecha'] ?? ''), 0, 10);

            if ($tval < 0) {
                $countDevol++;
                $valorDevol += abs($tval);
                continue;
            }
            if ($tval == 0) continue;

            $total += $tval;
            $countTickets++;
            if ($fecha !== '') $fechas[$fecha] = true;

            // Costo desde detalles (solo líneas con cantidad positiva)
            foreach ($detallesPorTicket[$tid] ?? [] as $d) {
                $qty = floatval($d['cantidad'] ?? 0);
                if ($qty > 0) {
                    $costo += $qty * floatval($d['costo_unitario'] ?? 0);
                }
            }

            // Método de pago: usa ventas_pagos si existe, sino campo cabecera
            if (isset($pagosMap[$tid])) {
                foreach ($pagosMap[$tid] as $mp => $monto) {
                    $metodosPago[$mp] = ($metodosPago[$mp] ?? 0.0) + $monto;
                }
            } else {
                $mp = (string)($t['metodo_pago'] ?: 'Efectivo');
                $metodosPago[$mp] = ($metodosPago[$mp] ?? 0.0) + $tval;
            }

            // Canal y tipo de servicio
            $canal = (string)($t['canal'] ?? $t['tipo_venta'] ?? 'POS');
            $porCanal[$canal] = ($porCanal[$canal] ?? 0.0) + $tval;

            $ts = (string)($t['tipo_servicio'] ?? 'venta');
            $porTipoServicio[$ts] = ($porTipoServicio[$ts] ?? 0.0) + $tval;
        }

        // Venta neta: suma de ventas_pagos donde existan, sino = total
        $neta = !empty($pagosMap)
            ? array_sum(array_map(fn($mp) => array_sum($mp), $pagosMap))
            : $total;

        $diasActivos   = count($fechas);
        $gananciaBruta = $total - $costo;
        $margenPct     = $total > 0 ? ($gananciaBruta / $total) * 100 : 0.0;

        return [
            'total'             => $total,
            'neta'              => $neta,
            'costo'             => $costo,
            'ganancia_bruta'    => $gananciaBruta,
            'margen_bruto_pct'  => $margenPct,
            'count_tickets'     => $countTickets,
            'ticket_promedio'   => $countTickets > 0 ? $total / $countTickets : 0.0,
            'dias_activos'      => $diasActivos,
            'promedio_diario'   => $diasActivos > 0 ? $total / $diasActivos : 0.0,
            'devoluciones'      => ['count' => $countDevol, 'valor' => $valorDevol],
            'por_metodo_pago'   => $metodosPago,
            'por_canal'         => $porCanal,
            'por_tipo_servicio' => $porTipoServicio,
        ];
    }
}

// ─── BM_GASTOS ────────────────────────────────────────────────────────────────
if (!function_exists('_bm_calc_gastos')) {
    function _bm_calc_gastos(PDO $pdo, array $p): array
    {
        $bindsDate  = [$p['fecha_inicio'], $p['fecha_fin']];
        $sucFilter  = $p['id_sucursal'] !== null ? ' AND id_sucursal = ?' : '';
        $sucBinds   = $p['id_sucursal'] !== null ? [$p['id_sucursal']] : [];

        // Gastos por categoría
        $st = $pdo->prepare(
            "SELECT COALESCE(categoria,'Sin categoría') AS categoria, SUM(monto) AS total
             FROM gastos_historial
             WHERE DATE(fecha) BETWEEN ? AND ? $sucFilter
             GROUP BY categoria
             ORDER BY total DESC"
        );
        $st->execute(array_merge($bindsDate, $sucBinds));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $porCategoria = [];
        $totalGastos  = 0.0;
        foreach ($rows as $r) {
            $porCategoria[(string)$r['categoria']] = floatval($r['total']);
            $totalGastos += floatval($r['total']);
        }

        // Mermas
        $totalMermas = 0.0;
        try {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(total_costo_perdida),0)
                 FROM mermas_cabecera
                 WHERE DATE(fecha) BETWEEN ? AND ?"
            );
            $st->execute($bindsDate);
            $totalMermas = floatval($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {}

        // Compras (respeta filtro de sucursal/almacén si las columnas existen)
        $totalCompras = 0.0;
        try {
            $sqlC   = "SELECT COALESCE(SUM(total),0) FROM compras_cabecera
                       WHERE DATE(fecha) BETWEEN ? AND ?
                         AND COALESCE(estado,'PROCESADA') != 'CANCELADA'";
            $bindC  = $bindsDate;

            if ($p['id_sucursal'] !== null) {
                $chk = $pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compras_cabecera'
                       AND COLUMN_NAME='id_sucursal'"
                );
                $chk->execute();
                if ((int)$chk->fetchColumn() > 0) {
                    $sqlC   .= ' AND id_sucursal = ?';
                    $bindC[] = $p['id_sucursal'];
                }
            }

            $st = $pdo->prepare($sqlC);
            $st->execute($bindC);
            $totalCompras = floatval($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {}

        return [
            'total'           => $totalGastos,
            'por_categoria'   => $porCategoria,
            'mermas'          => $totalMermas,
            'compras'         => $totalCompras,
            'ganancia_neta'   => null,  // calculado en bm_calcular() si BM_VENTAS también pedido
            'margen_neto_pct' => null,
        ];
    }
}

// ─── BM_PRODUCTOS ─────────────────────────────────────────────────────────────
if (!function_exists('_bm_calc_productos')) {
    function _bm_calc_productos(array $detalles, int $limite): array
    {
        $agg = [];  // sku => [codigo, nombre, categoria, cantidad, monto, ganancia]

        foreach ($detalles as $d) {
            $sku    = (string)($d['id_producto'] ?? '');
            $qty    = floatval($d['cantidad'] ?? 0);
            if ($qty <= 0) continue;  // excluye líneas de devolución
            $precio = floatval($d['precio'] ?? 0);
            $costo  = floatval($d['costo_unitario'] ?? 0);
            $nombre = (string)($d['nombre_prod'] ?? $d['nombre_producto'] ?? $sku);
            $cat    = (string)($d['categoria'] ?? '');

            if (!isset($agg[$sku])) {
                $agg[$sku] = [
                    'codigo'      => $sku,
                    'nombre'      => $nombre,
                    'categoria'   => $cat,
                    'es_elaborado'=> (int)($d['es_elaborado'] ?? 0),
                    'cantidad'    => 0.0,
                    'monto'       => 0.0,
                    'ganancia'    => 0.0,
                ];
            }
            $agg[$sku]['cantidad'] += $qty;
            $agg[$sku]['monto']    += $qty * $precio;
            $agg[$sku]['ganancia'] += $qty * ($precio - $costo);
        }

        $prods = array_values($agg);

        // Top por monto
        usort($prods, fn($a, $b) => $b['monto'] <=> $a['monto']);
        $topMonto = array_slice($prods, 0, $limite);

        // Top por ganancia
        $byGanancia = $prods;
        usort($byGanancia, fn($a, $b) => $b['ganancia'] <=> $a['ganancia']);
        $topGanancia = array_slice($byGanancia, 0, $limite);

        // Bottom por monto (menos vendidos en ingresos)
        $bottom = array_slice(array_reverse($prods), 0, $limite);

        // Bottom por ganancia (menos rentables)
        $byGananciaAsc = $byGanancia;
        array_reverse($byGananciaAsc);  // ya ordenado DESC, revertir da ASC
        $bottomGanancia = array_slice(array_reverse($byGanancia), 0, $limite);

        // Por categoría
        $porCat = [];
        foreach ($prods as $pr) {
            $c = $pr['categoria'] !== '' ? $pr['categoria'] : 'Sin categoría';
            if (!isset($porCat[$c])) {
                $porCat[$c] = ['cantidad' => 0.0, 'monto' => 0.0, 'ganancia' => 0.0];
            }
            $porCat[$c]['cantidad'] += $pr['cantidad'];
            $porCat[$c]['monto']    += $pr['monto'];
            $porCat[$c]['ganancia'] += $pr['ganancia'];
        }
        uasort($porCat, fn($a, $b) => $b['monto'] <=> $a['monto']);

        return [
            'top_por_monto'       => $topMonto,
            'top_por_ganancia'    => $topGanancia,
            'bottom_por_monto'    => $bottom,
            'bottom_por_ganancia' => $bottomGanancia,
            'por_categoria'       => $porCat,
        ];
    }
}

// ─── BM_CLIENTES ──────────────────────────────────────────────────────────────
if (!function_exists('_bm_calc_clientes')) {
    function _bm_calc_clientes(array $tickets, int $limite): array
    {
        $agg = [];

        foreach ($tickets as $t) {
            $val = floatval($t['total'] ?? 0);
            if ($val <= 0) continue;

            $cliente = trim((string)($t['nombre_cliente'] ?? $t['cliente'] ?? ''));
            if ($cliente === '') $cliente = 'Anónimo';

            if (!isset($agg[$cliente])) {
                $agg[$cliente] = ['cliente' => $cliente, 'tickets' => 0, 'monto' => 0.0];
            }
            $agg[$cliente]['tickets']++;
            $agg[$cliente]['monto'] += $val;
        }

        $list = array_values($agg);
        usort($list, fn($a, $b) => $b['monto'] <=> $a['monto']);

        return [
            'top'          => array_slice($list, 0, $limite),
            'count_unicos' => count($agg),
        ];
    }
}

// ─── BM_CAJERO ────────────────────────────────────────────────────────────────
if (!function_exists('_bm_calc_cajero')) {
    function _bm_calc_cajero(PDO $pdo, array $p, array $tickets): array
    {
        // Agrupación de tickets por cajero
        $porCajero = [];
        foreach ($tickets as $t) {
            $cajero = trim((string)($t['nombre_cajero'] ?? $t['cajero'] ?? 'Sin cajero'));
            $val    = floatval($t['total'] ?? 0);

            if (!isset($porCajero[$cajero])) {
                $porCajero[$cajero] = [
                    'cajero'        => $cajero,
                    'sesiones'      => 0,
                    'tickets'       => 0,
                    'ventas'        => 0.0,
                    'devoluciones'  => 0.0,
                    'diferencia'    => 0.0,
                ];
            }
            if ($val > 0) {
                $porCajero[$cajero]['tickets']++;
                $porCajero[$cajero]['ventas'] += $val;
            } elseif ($val < 0) {
                $porCajero[$cajero]['devoluciones'] += abs($val);
            }
        }

        // Sesiones del período
        $sesiones  = [];
        $totAper   = 0.0;
        $totSist   = 0.0;
        $totReal   = 0.0;
        $totDiff   = 0.0;

        try {
            $sqlSes  = "SELECT cs.*
                        FROM caja_sesiones cs
                        WHERE cs.fecha_contable BETWEEN ? AND ?";
            $bSes    = [$p['fecha_inicio'], $p['fecha_fin']];

            if ($p['id_sucursal'] !== null) {
                $sqlSes .= ' AND cs.id_sucursal = ?';
                $bSes[]  = $p['id_sucursal'];
            }
            $sqlSes .= ' ORDER BY cs.fecha_contable ASC, cs.id ASC';

            $st = $pdo->prepare($sqlSes);
            $st->execute($bSes);
            $sesiones = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($sesiones as $s) {
                $aper  = floatval($s['monto_apertura']         ?? 0);
                $sist  = floatval($s['cierre_sistema']         ?? $s['monto_cierre_sistema'] ?? 0);
                $real  = floatval($s['cierre_real']            ?? $s['monto_cierre_real']    ?? 0);
                $diff  = floatval($s['diferencia']             ?? ($real - $sist));
                $totAper += $aper;
                $totSist += $sist;
                $totReal += $real;
                $totDiff += $diff;

                // Incrementar contador de sesiones por cajero
                $caj = trim((string)($s['nombre_cajero'] ?? ''));
                if ($caj !== '' && isset($porCajero[$caj])) {
                    $porCajero[$caj]['sesiones']++;
                    $porCajero[$caj]['diferencia'] += $diff;
                }
            }
        } catch (Throwable $e) {}

        return [
            'sesiones'             => $sesiones,
            'total_aperturas'      => $totAper,
            'total_cierre_sistema' => $totSist,
            'total_cierre_real'    => $totReal,
            'total_diferencias'    => $totDiff,
            'por_cajero'           => array_values($porCajero),
        ];
    }
}

// ─── BM_SERIES ────────────────────────────────────────────────────────────────
if (!function_exists('_bm_calc_series')) {
    function _bm_calc_series(PDO $pdo, array $p): array
    {
        $wReal     = ventas_reales_where_clause('v');
        $sucFilterV = $p['id_sucursal'] !== null ? ' AND v.id_sucursal = ?' : '';
        $sucBindV   = $p['id_sucursal'] !== null ? [$p['id_sucursal']] : [];
        $sucFilterG = $p['id_sucursal'] !== null ? ' AND id_sucursal = ?' : '';
        $sucBindG   = $p['id_sucursal'] !== null ? [$p['id_sucursal']] : [];

        $csjoin = "LEFT JOIN caja_sesiones cs ON (v.id_caja = cs.id OR v.id_sesion_caja = cs.id)";

        $sql = "SELECT
                    dates.dia,
                    COALESCE((
                        SELECT SUM(CASE WHEN v.total > 0 THEN v.total ELSE 0 END)
                        FROM ventas_cabecera v $csjoin
                        WHERE v.id_empresa = ?
                          AND IFNULL(cs.fecha_contable, DATE(v.fecha)) = dates.dia
                          AND $wReal AND v.tipo_servicio != 'reserva' $sucFilterV
                    ), 0) AS venta,
                    COALESCE((
                        SELECT SUM(CASE WHEN vd.cantidad > 0 THEN vd.cantidad * p.costo ELSE 0 END)
                        FROM ventas_detalle vd
                        JOIN ventas_cabecera v ON vd.id_venta_cabecera = v.id
                        JOIN productos p ON vd.id_producto = p.codigo
                        $csjoin
                        WHERE v.id_empresa = ?
                          AND IFNULL(cs.fecha_contable, DATE(v.fecha)) = dates.dia
                          AND v.total > 0 AND $wReal AND v.tipo_servicio != 'reserva' $sucFilterV
                    ), 0) AS costo,
                    COALESCE((
                        SELECT COUNT(v.id)
                        FROM ventas_cabecera v $csjoin
                        WHERE v.id_empresa = ?
                          AND IFNULL(cs.fecha_contable, DATE(v.fecha)) = dates.dia
                          AND v.total > 0 AND $wReal AND v.tipo_servicio != 'reserva' $sucFilterV
                    ), 0) AS transacciones,
                    COALESCE((
                        SELECT SUM(monto) FROM gastos_historial
                        WHERE DATE(fecha) = dates.dia $sucFilterG
                    ), 0) AS gastos
                FROM (
                    SELECT DISTINCT IFNULL(cs.fecha_contable, DATE(v.fecha)) AS dia
                    FROM ventas_cabecera v $csjoin
                    WHERE v.id_empresa = ?
                      AND IFNULL(cs.fecha_contable, DATE(v.fecha)) BETWEEN ? AND ?
                      AND $wReal AND v.tipo_servicio != 'reserva' $sucFilterV
                    UNION
                    SELECT DISTINCT DATE(fecha) AS dia
                    FROM gastos_historial
                    WHERE DATE(fecha) BETWEEN ? AND ? $sucFilterG
                ) AS dates
                ORDER BY dates.dia ASC";

        $binds = array_merge(
            [$p['id_empresa']], $sucBindV,       // bloque venta
            [$p['id_empresa']], $sucBindV,       // bloque costo
            [$p['id_empresa']], $sucBindV,       // bloque transacciones
            $sucBindG,                            // bloque gastos
            [$p['id_empresa'], $p['fecha_inicio'], $p['fecha_fin']], $sucBindV,  // UNION ventas
            [$p['fecha_inicio'], $p['fecha_fin']], $sucBindG                      // UNION gastos
        );

        $st = $pdo->prepare($sql);
        $st->execute($binds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $series = [];
        foreach ($rows as $r) {
            $venta  = floatval($r['venta']);
            $costo  = floatval($r['costo']);
            $series[] = [
                'fecha'         => $r['dia'],
                'venta'         => $venta,
                'costo'         => $costo,
                'ganancia'      => $venta - $costo,
                'gastos'        => floatval($r['gastos']),
                'transacciones' => (int)$r['transacciones'],
            ];
        }

        return $series;
    }
}

// ─── BM_INVENTARIO ────────────────────────────────────────────────────────────
if (!function_exists('_bm_calc_inventario')) {
    function _bm_calc_inventario(PDO $pdo, array $p): array
    {
        $empFilter = ' AND p.id_empresa = ?';
        $almFilter = $p['id_almacen'] !== null ? ' AND s.id_almacen = ?' : '';
        $binds     = [$p['id_empresa']];
        if ($p['id_almacen'] !== null) $binds[] = $p['id_almacen'];

        $vcosto = $vventa = 0.0;
        try {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(s.cantidad * p.costo),  0) AS vcosto,
                        COALESCE(SUM(s.cantidad * p.precio), 0) AS vventa
                 FROM stock_almacen s
                 JOIN productos p ON s.id_producto = p.codigo
                 WHERE 1=1 $empFilter $almFilter"
            );
            $st->execute($binds);
            $row    = $st->fetch(PDO::FETCH_ASSOC);
            $vcosto = floatval($row['vcosto'] ?? 0);
            $vventa = floatval($row['vventa'] ?? 0);
        } catch (Throwable $e) {}

        $stockCritico = 0;
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM stock_almacen s
                 JOIN productos p ON s.id_producto = p.codigo
                 WHERE COALESCE(s.cantidad,0) <= COALESCE(p.stock_minimo,0) $empFilter $almFilter"
            );
            $st->execute($binds);
            $stockCritico = (int)$st->fetchColumn();
        } catch (Throwable $e) {}

        return [
            'valor_costo'      => $vcosto,
            'valor_venta'      => $vventa,
            'margen_potencial' => $vventa - $vcosto,
            'stock_critico'    => $stockCritico,
        ];
    }
}

// ─── BM_PEDIDOS ───────────────────────────────────────────────────────────────
if (!function_exists('_bm_calc_pedidos')) {
    function _bm_calc_pedidos(PDO $pdo, array $p): array
    {
        $sucFilter = $p['id_sucursal'] !== null ? ' AND id_sucursal = ?' : '';
        $sucBinds  = $p['id_sucursal'] !== null ? [$p['id_sucursal']] : [];

        // Pedidos web
        $cntWeb = $montoWeb = 0.0;
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS m
                 FROM pedidos_cabecera
                 WHERE id_empresa = ? AND DATE(fecha) BETWEEN ? AND ? $sucFilter"
            );
            $st->execute(array_merge(
                [$p['id_empresa'], $p['fecha_inicio'], $p['fecha_fin']],
                $sucBinds
            ));
            $r       = $st->fetch(PDO::FETCH_ASSOC);
            $cntWeb  = (int)($r['n'] ?? 0);
            $montoWeb = floatval($r['m'] ?? 0);
        } catch (Throwable $e) {}

        // Reservas confirmadas (ya filtradas por ventas_reales_where_clause)
        $cntRes = $ventaRes = $ganRes = 0.0;
        try {
            [$where, $binds] = _bm_build_where($p);
            // ventas_reales_where_clause ya incluye (tipo_servicio != 'reserva' OR estado_pago = 'confirmado')
            // Añadimos AND tipo_servicio = 'reserva' para quedarnos solo con reservas confirmadas
            $st = $pdo->prepare(
                "SELECT COUNT(DISTINCT v.id) AS n,
                        COALESCE(SUM(v.total),0) AS venta,
                        COALESCE(SUM(vd.cantidad * (vd.precio - p.costo)),0) AS ganancia
                 FROM ventas_cabecera v
                 LEFT JOIN caja_sesiones cs
                        ON (v.id_caja = cs.id OR v.id_sesion_caja = cs.id)
                 LEFT JOIN ventas_detalle vd ON vd.id_venta_cabecera = v.id
                 LEFT JOIN productos p ON vd.id_producto = p.codigo
                 WHERE $where AND v.tipo_servicio = 'reserva'"
            );
            $st->execute($binds);
            $r       = $st->fetch(PDO::FETCH_ASSOC);
            $cntRes  = (int)($r['n'] ?? 0);
            $ventaRes = floatval($r['venta'] ?? 0);
            $ganRes   = floatval($r['ganancia'] ?? 0);
        } catch (Throwable $e) {}

        return [
            'web'      => ['count' => (int)$cntWeb,  'total'   => (float)$montoWeb],
            'reservas' => ['count' => (int)$cntRes,  'venta'   => (float)$ventaRes, 'ganancia' => (float)$ganRes],
        ];
    }
}
