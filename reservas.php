<?php
// ARCHIVO: reservas.php v3.0 — CRUD Completo de Reservas
ini_set('display_errors', 0);
session_start();
require_once 'db.php';
require_once 'config_loader.php';

$sucursalID = intval($config['id_sucursal']);
$idAlmacen  = intval($config['id_almacen']);
$empID      = intval($config['id_empresa']);

// Migración automática: columna de canal de origen
$pdo->exec("ALTER TABLE ventas_cabecera ADD COLUMN IF NOT EXISTS canal_origen VARCHAR(30) DEFAULT 'POS'");

function safeStr(string $s, int $max = 255): string {
    return mb_substr(trim($s), 0, $max);
}

// ══════════════════════════════════════════════════════════════════════════════
// POST — acciones JSON
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    try {
        // ── CREAR ─────────────────────────────────────────────────────────────
        if ($action === 'create') {
            $clienteNombre = safeStr($input['cliente_nombre'] ?? 'Sin nombre', 100);
            $clienteTel    = safeStr($input['cliente_telefono'] ?? '', 50);
            $clienteDir    = safeStr($input['cliente_direccion'] ?? '', 255);
            $idCliente     = intval($input['id_cliente'] ?? 0);
            $fechaReserva  = $input['fecha_reserva'] ?? date('Y-m-d H:i:s');
            $notas         = safeStr($input['notas'] ?? '', 1000);
            $metodo        = safeStr($input['metodo_pago'] ?? 'Efectivo', 50);
            $abono         = floatval($input['abono'] ?? 0);
            $estadoPago    = safeStr($input['estado_pago'] ?? 'pendiente', 20);
            $items         = $input['items'] ?? [];
            $canalesValidos = ['POS','Web','WhatsApp','Teléfono','Kiosko','Presencial','Otro'];
            $canalOrigen   = in_array($input['canal_origen'] ?? '', $canalesValidos)
                             ? $input['canal_origen'] : 'POS';

            if (empty($items)) throw new Exception('Debe agregar al menos un producto.');

            $total = array_reduce($items, fn($c, $i) => $c + floatval($i['precio']) * floatval($i['cantidad']), 0.0);
            $uuid  = uniqid('R-', true);

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO ventas_cabecera
                (uuid_venta, fecha, total, metodo_pago, id_sucursal, id_almacen, tipo_servicio,
                 fecha_reserva, notas, cliente_nombre, cliente_telefono, cliente_direccion,
                 abono, estado_pago, id_empresa, estado_reserva, sincronizado, id_caja, id_cliente, canal_origen)
                VALUES (?,NOW(),?,?,?,?,'reserva',?,?,?,?,?,?,?,?,'PENDIENTE',0,1,?,?)")
                ->execute([$uuid, $total, $metodo, $sucursalID, $idAlmacen,
                    $fechaReserva, $notas, $clienteNombre, $clienteTel, $clienteDir,
                    $abono, $estadoPago, $empID, $idCliente, $canalOrigen]);
            $idVenta = $pdo->lastInsertId();

            foreach ($items as $it) {
                $pdo->prepare("INSERT INTO ventas_detalle
                    (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto, codigo_producto, categoria_producto)
                    VALUES (?,?,?,?,?,?,?)")
                    ->execute([$idVenta, safeStr($it['codigo'], 100), floatval($it['cantidad']),
                        floatval($it['precio']), safeStr($it['nombre'], 255),
                        safeStr($it['codigo'], 100), safeStr($it['categoria'] ?? 'General', 100)]);
            }
            $pdo->commit();
            echo json_encode(['status' => 'success', 'id' => $idVenta]);
        }

        // ── EDITAR ────────────────────────────────────────────────────────────
        elseif ($action === 'update') {
            $idVenta       = intval($input['id']);
            $clienteNombre = safeStr($input['cliente_nombre'] ?? '', 100);
            $clienteTel    = safeStr($input['cliente_telefono'] ?? '', 50);
            $clienteDir    = safeStr($input['cliente_direccion'] ?? '', 255);
            $idCliente     = intval($input['id_cliente'] ?? 0);
            $fechaReserva  = $input['fecha_reserva'] ?? '';
            $notas         = safeStr($input['notas'] ?? '', 1000);
            $metodo        = safeStr($input['metodo_pago'] ?? 'Efectivo', 50);
            $abono         = floatval($input['abono'] ?? 0);
            $items         = $input['items'] ?? [];
            $canalesValidos = ['POS','Web','WhatsApp','Teléfono','Kiosko','Presencial','Otro'];
            $canalOrigen   = in_array($input['canal_origen'] ?? '', $canalesValidos)
                             ? $input['canal_origen'] : 'POS';

            if (!$idVenta) throw new Exception('ID inválido.');
            if (empty($items)) throw new Exception('Debe agregar al menos un producto.');

            $total = array_reduce($items, fn($c, $i) => $c + floatval($i['precio']) * floatval($i['cantidad']), 0.0);

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE ventas_cabecera SET
                cliente_nombre=?, cliente_telefono=?, cliente_direccion=?, id_cliente=?,
                fecha_reserva=?, notas=?, metodo_pago=?, total=?, abono=?, canal_origen=?
                WHERE id=? AND id_sucursal=? AND tipo_servicio='reserva'")
                ->execute([$clienteNombre, $clienteTel, $clienteDir, $idCliente,
                    $fechaReserva, $notas, $metodo, $total, $abono, $canalOrigen, $idVenta, $sucursalID]);

            $pdo->prepare("DELETE FROM ventas_detalle WHERE id_venta_cabecera=?")->execute([$idVenta]);
            foreach ($items as $it) {
                $pdo->prepare("INSERT INTO ventas_detalle
                    (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto, codigo_producto, categoria_producto)
                    VALUES (?,?,?,?,?,?,?)")
                    ->execute([$idVenta, safeStr($it['codigo'], 100), floatval($it['cantidad']),
                        floatval($it['precio']), safeStr($it['nombre'], 255),
                        safeStr($it['codigo'], 100), safeStr($it['categoria'] ?? 'General', 100)]);
            }
            $pdo->commit();
            echo json_encode(['status' => 'success']);
        }

        // ── VER DETALLE (para modal editar) ───────────────────────────────────
        elseif ($action === 'get_detail') {
            $idVenta = intval($input['id']);
            $stmtH   = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id=? AND id_sucursal=?");
            $stmtH->execute([$idVenta, $sucursalID]);
            $header  = $stmtH->fetch(PDO::FETCH_ASSOC);
            if (!$header) throw new Exception('Reserva no encontrada.');

            $stmtD = $pdo->prepare("SELECT id_producto AS codigo, nombre_producto AS nombre,
                categoria_producto AS categoria, cantidad, precio
                FROM ventas_detalle WHERE id_venta_cabecera=?");
            $stmtD->execute([$idVenta]);
            echo json_encode(['status' => 'success', 'data' => array_merge($header, ['items' => $stmtD->fetchAll(PDO::FETCH_ASSOC)])]);
        }

        // ── ENTREGAR (+ Kardex) ────────────────────────────────────────────────
        elseif ($action === 'complete') {
            $idVenta = intval($input['id']);
            $alertas = [];
            require_once 'kardex_engine.php';
            if (class_exists('KardexEngine')) {
                $kardex    = new KardexEngine($pdo);
                $stmtItems = $pdo->prepare("SELECT id_producto, cantidad, precio, nombre_producto
                    FROM ventas_detalle WHERE id_venta_cabecera=?");
                $stmtItems->execute([$idVenta]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                $pdo->beginTransaction();
                foreach ($items as $item) {
                    $sku = $item['id_producto'];
                    $qty = floatval($item['cantidad']);
                    $stmtEs = $pdo->prepare("SELECT es_servicio FROM productos WHERE codigo=?");
                    $stmtEs->execute([$sku]);
                    if ((int)$stmtEs->fetchColumn()) continue;

                    $stmtSt = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
                    $stmtSt->execute([$sku, $idAlmacen]);
                    $stockActual = floatval($stmtSt->fetchColumn());
                    if ($stockActual < $qty) $alertas[] = "{$item['nombre_producto']}: stock={$stockActual}, necesario={$qty}";

                    $kardex->registrarMovimiento($sku, $idAlmacen, $config['id_sucursal'],
                        'VENTA', -$qty, "ENTREGA-RESERVA-{$idVenta}",
                        floatval($item['precio']), 'reservas', date('Y-m-d H:i:s'));
                }
                $pdo->commit();
            }
            $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva='ENTREGADO' WHERE id=?")->execute([$idVenta]);
            echo empty($alertas)
                ? json_encode(['status' => 'success'])
                : json_encode(['status' => 'warning', 'alertas' => $alertas, 'msg' => 'Entregado con advertencias de stock.']);
        }

        // ── CANCELAR ──────────────────────────────────────────────────────────
        elseif ($action === 'cancel') {
            $idVenta = intval($input['id']);
            $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva='CANCELADO' WHERE id=? AND id_sucursal=?")
                ->execute([$idVenta, $sucursalID]);
            echo json_encode(['status' => 'success']);
        }

        // ── ENVIAR A COCINA ────────────────────────────────────────────────────
        elseif ($action === 'send_to_kitchen') {
            $idVenta  = intval($input['id']);
            $stmtChk  = $pdo->prepare("SELECT id FROM comandas WHERE id_venta=?");
            $stmtChk->execute([$idVenta]);
            if ($stmtChk->fetch()) throw new Exception('Ya está en cocina.');

            $stmtEl = $pdo->prepare("SELECT d.cantidad, p.nombre FROM ventas_detalle d
                JOIN productos p ON d.id_producto=p.codigo
                WHERE d.id_venta_cabecera=? AND p.es_elaborado=1");
            $stmtEl->execute([$idVenta]);
            $elab = $stmtEl->fetchAll(PDO::FETCH_ASSOC);
            if (empty($elab)) throw new Exception('No hay elaborados en esta reserva.');

            $pdo->prepare("INSERT INTO comandas (id_venta, items_json, estado, fecha_creacion) VALUES (?,?,'pendiente',NOW())")
                ->execute([$idVenta, json_encode(array_map(fn($it) => ['qty' => (float)$it['cantidad'], 'name' => $it['nombre'], 'note' => 'RESERVA'], $elab))]);
            echo json_encode(['status' => 'success']);
        }

        // ── CONFIRMAR PAGO ────────────────────────────────────────────────────
        elseif ($action === 'confirm_payment') {
            $idVenta = intval($input['id']);
            $pdo->prepare("UPDATE ventas_cabecera SET estado_pago='confirmado' WHERE id=?")->execute([$idVenta]);
            $stmtV = $pdo->prepare("SELECT uuid_venta, cliente_nombre FROM ventas_cabecera WHERE id=?");
            $stmtV->execute([$idVenta]);
            $v = $stmtV->fetch(PDO::FETCH_ASSOC);
            if ($v) {
                $pdo->prepare("INSERT INTO chat_messages (client_uuid, sender, message, is_read) VALUES (?,?,?,0)")
                    ->execute(['PAGO_CONFIRMADO_' . $v['uuid_venta'], 'admin',
                        "✓ Pago confirmado para {$v['cliente_nombre']} (#{$idVenta})."]);
            }
            echo json_encode(['status' => 'success']);
        }

        // ── ACTUALIZAR ESTADO ─────────────────────────────────────────────────
        elseif ($action === 'update_estado') {
            $idVenta = intval($input['id']);
            $estado  = safeStr($input['estado'] ?? '', 30);
            $nota    = safeStr($input['nota'] ?? '', 1000);
            $validos = ['PENDIENTE','EN_PREPARACION','EN_CAMINO','ENTREGADO','CANCELADO'];
            if (!in_array($estado, $validos)) throw new Exception('Estado inválido.');
            $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva=?, notas=?
                           WHERE id=? AND tipo_servicio='reserva' AND id_sucursal=?")
                ->execute([$estado, $nota, $idVenta, $sucursalID]);
            echo json_encode(['status' => 'success']);
        }

        // ── CREAR CLIENTE RÁPIDO ──────────────────────────────────────────────
        elseif ($action === 'create_client') {
            $nombre    = safeStr($input['nombre'] ?? '', 100);
            $telefono  = safeStr($input['telefono'] ?? '', 20);
            $direccion = safeStr($input['direccion'] ?? '', 500);
            $categoria = in_array($input['categoria'] ?? '', ['Regular','VIP','Corporativo','Moroso','Empleado'])
                ? $input['categoria'] : 'Regular';
            if (!$nombre) throw new Exception('Nombre requerido.');
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
            $pdo->prepare("INSERT INTO clientes (nombre, telefono, direccion, categoria, uuid, origen) VALUES (?,?,?,?,?,'Manual')")
                ->execute([$nombre, $telefono, $direccion, $categoria, $uuid]);
            echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId(),
                'nombre' => $nombre, 'telefono' => $telefono, 'direccion' => $direccion]);
        }

        // ── IMPORTAR ICS ──────────────────────────────────────────────────────
        elseif ($action === 'import_ics') {
            $content = $input['content'] ?? '';
            if (empty($content)) throw new Exception('Archivo vacío.');
            preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $content, $matches);
            $count = 0;
            foreach ($matches[0] as $event) {
                preg_match('/SUMMARY[^:]*:(.*)/', $event, $sm);
                preg_match('/DTSTART[^:]*:(.*)/', $event, $dt);
                preg_match('/UID[^:]*:(.*)/', $event, $uid);
                $title = trim($sm[1] ?? 'Reserva Importada');
                $startStr = trim($dt[1] ?? '');
                $uuid = trim($uid[1] ?? uniqid('ics_', true));
                if (!$startStr) continue;
                $date = null;
                if (preg_match('/(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})/', $startStr, $d)) $date = "{$d[1]}-{$d[2]}-{$d[3]} {$d[4]}:{$d[5]}:00";
                elseif (preg_match('/(\d{4})(\d{2})(\d{2})/', $startStr, $d)) $date = "{$d[1]}-{$d[2]}-{$d[3]} 12:00:00";
                if ($date) {
                    $stmtC = $pdo->prepare("SELECT id FROM ventas_cabecera WHERE uuid_venta=?");
                    $stmtC->execute([$uuid]);
                    if ($stmtC->fetch()) continue;
                    $pdo->prepare("INSERT INTO ventas_cabecera (uuid_venta,fecha,total,metodo_pago,id_sucursal,id_almacen,tipo_servicio,cliente_nombre,fecha_reserva,id_empresa,estado_reserva,sincronizado,id_caja,canal_origen) VALUES (?,NOW(),0,'Pendiente',?,?,'reserva',?,?,?,'PENDIENTE',0,1,'ICS')")
                        ->execute([$uuid, $sucursalID, $idAlmacen, $title, $date, $empID]);
                    $count++;
                }
            }
            echo json_encode(['status' => 'success', 'count' => $count]);
        }

        else throw new Exception("Acción desconocida: $action");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// GET — búsquedas AJAX
// ══════════════════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($_GET['action'] === 'search_clients') {
        $stmt = $pdo->prepare("SELECT id, nombre, telefono, direccion FROM clientes
            WHERE activo=1 AND (nombre LIKE ? OR telefono LIKE ?) ORDER BY nombre LIMIT 10");
        $stmt->execute(["%$q%", "%$q%"]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } elseif ($_GET['action'] === 'search_products') {
        $stmt = $pdo->prepare("SELECT p.codigo, p.nombre, p.precio, p.categoria,
            p.es_servicio, p.es_reservable,
            COALESCE(SUM(s.cantidad),0) as stock
            FROM productos p
            LEFT JOIN stock_almacen s ON s.id_producto=p.codigo AND s.id_almacen=?
            WHERE p.activo=1 AND p.id_empresa=? AND p.es_materia_prima=0
              AND (p.nombre LIKE ? OR p.codigo LIKE ?)
            GROUP BY p.codigo ORDER BY p.nombre LIMIT 10");
        $stmt->execute([$idAlmacen, $empID, "%$q%", "%$q%"]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } elseif ($_GET['action'] === 'month_data') {
        $year  = intval($_GET['year']  ?? date('Y'));
        $month = intval($_GET['month'] ?? date('n'));
        $stmt  = $pdo->prepare("SELECT id, cliente_nombre, cliente_telefono,
            fecha_reserva, total, abono,
            (total - COALESCE(abono,0)) AS deuda,
            COALESCE(estado_reserva,'PENDIENTE') AS estado_reserva,
            COALESCE(estado_pago,'pendiente')    AS estado_pago,
            metodo_pago, notas
            FROM ventas_cabecera
            WHERE tipo_servicio='reserva' AND id_sucursal=?
              AND YEAR(fecha_reserva)=? AND MONTH(fecha_reserva)=?
            ORDER BY fecha_reserva ASC");
        $stmt->execute([$sucursalID, $year, $month]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

    } elseif ($_GET['action'] === 'check_client_phone') {
        $tel = preg_replace('/\D/', '', trim($_GET['tel'] ?? ''));
        if (strlen($tel) < 5) { echo json_encode(['exists' => false, 'cliente' => null]); exit; }
        $stmt = $pdo->prepare("SELECT id, nombre, telefono, categoria FROM clientes
            WHERE REPLACE(REPLACE(REPLACE(telefono,' ',''),'+',''),'-','') LIKE ? AND activo=1 LIMIT 1");
        $stmt->execute(["%$tel%"]);
        $cl = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['exists' => (bool)$cl, 'cliente' => $cl ?: null]);

    } elseif ($_GET['action'] === 'report_data') {
        $year  = intval($_GET['year']  ?? date('Y'));
        $month = intval($_GET['month'] ?? date('n'));
        $MONTHS_ES = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                      'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

        // Sección 1: Todos los productos de reservas pendientes con déficit de stock
        $stmt = $pdo->prepare("
            SELECT vd.id_producto AS codigo, vd.nombre_producto AS nombre,
                   SUM(vd.cantidad) AS total_reservado,
                   COALESCE(MAX(sa.cantidad), 0) AS stock_actual,
                   GREATEST(0, SUM(vd.cantidad) - COALESCE(MAX(sa.cantidad), 0)) AS deficit,
                   COUNT(DISTINCT vc.id) AS num_reservas
            FROM ventas_cabecera vc
            JOIN ventas_detalle vd ON vd.id_venta_cabecera = vc.id
            LEFT JOIN stock_almacen sa ON sa.id_producto = vd.id_producto AND sa.id_almacen = ?
            LEFT JOIN productos p ON p.codigo = vd.id_producto
            WHERE vc.tipo_servicio = 'reserva' AND vc.id_sucursal = ?
              AND (vc.estado_reserva = 'PENDIENTE' OR vc.estado_reserva IS NULL)
              AND COALESCE(p.es_servicio, 0) = 0
            GROUP BY vd.id_producto, vd.nombre_producto
            ORDER BY deficit DESC, nombre ASC");
        $stmt->execute([$idAlmacen, $sucursalID]);
        $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $sinStock = array_values(array_filter($allProducts, fn($p) => floatval($p['deficit']) > 0));

        // Sección 2: Por semana del mes indicado
        $stmt2 = $pdo->prepare("
            SELECT vd.id_producto AS codigo, vd.nombre_producto AS nombre,
                   SUM(vd.cantidad) AS total_reservado,
                   COALESCE(MAX(sa.cantidad), 0) AS stock_actual,
                   GREATEST(0, SUM(vd.cantidad) - COALESCE(MAX(sa.cantidad), 0)) AS deficit,
                   CASE
                     WHEN DAY(vc.fecha_reserva) BETWEEN 1  AND 7  THEN 1
                     WHEN DAY(vc.fecha_reserva) BETWEEN 8  AND 14 THEN 2
                     WHEN DAY(vc.fecha_reserva) BETWEEN 15 AND 21 THEN 3
                     ELSE 4
                   END AS semana
            FROM ventas_cabecera vc
            JOIN ventas_detalle vd ON vd.id_venta_cabecera = vc.id
            LEFT JOIN stock_almacen sa ON sa.id_producto = vd.id_producto AND sa.id_almacen = ?
            LEFT JOIN productos p ON p.codigo = vd.id_producto
            WHERE vc.tipo_servicio = 'reserva' AND vc.id_sucursal = ?
              AND (vc.estado_reserva = 'PENDIENTE' OR vc.estado_reserva IS NULL)
              AND YEAR(vc.fecha_reserva) = ? AND MONTH(vc.fecha_reserva) = ?
              AND COALESCE(p.es_servicio, 0) = 0
            GROUP BY vd.id_producto, vd.nombre_producto, semana
            ORDER BY semana ASC, deficit DESC, nombre ASC");
        $stmt2->execute([$idAlmacen, $sucursalID, $year, $month]);
        $byWeekRaw = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $byWeek = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($byWeekRaw as $row) { $byWeek[intval($row['semana'])][] = $row; }

        echo json_encode([
            'status'      => 'success',
            'sin_stock'   => $sinStock,
            'all_products'=> $allProducts,
            'by_week'     => $byWeek,
            'month'       => $month,
            'year'        => $year,
            'month_name'  => $MONTHS_ES[$month] ?? '',
        ]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// CONSULTA PRINCIPAL
// ══════════════════════════════════════════════════════════════════════════════
$filterEstado = $_GET['estado'] ?? 'PENDIENTE';
$filterQ      = trim($_GET['q'] ?? '');
$filterFecha  = trim($_GET['fecha'] ?? '');
$page         = max(1, intval($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$params = [];

// Para VERIFICANDO los pedidos vienen de shop.php con tipo_servicio='domicilio'/'recogida',
// nunca con 'reserva', así que no restringimos por tipo_servicio en ese caso.
if ($filterEstado === 'VERIFICANDO') {
    $where = ["c.id_sucursal=$sucursalID", "c.estado_pago='verificando'"];
} else {
    $where = ["c.tipo_servicio='reserva'", "c.id_sucursal=$sucursalID"];
    if ($filterEstado === 'PENDIENTE')     $where[] = "(c.estado_reserva='PENDIENTE' OR c.estado_reserva IS NULL)";
    elseif ($filterEstado === 'ENTREGADO') $where[] = "c.estado_reserva='ENTREGADO'";
    elseif ($filterEstado === 'CANCELADO') $where[] = "c.estado_reserva='CANCELADO'";
}
if ($filterQ)     { $where[] = "c.cliente_nombre LIKE ?"; $params[] = "%$filterQ%"; }
if ($filterFecha === 'hoy')     $where[] = "DATE(c.fecha_reserva)=CURDATE()";
elseif ($filterFecha === 'semana')  $where[] = "c.fecha_reserva BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY)";
elseif ($filterFecha === 'vencidas') $where[] = "c.fecha_reserva < NOW() AND (c.estado_reserva='PENDIENTE' OR c.estado_reserva IS NULL)";

$whereSQL = implode(' AND ', $where);

// KPIs
$stmtKpi = $pdo->prepare("SELECT
    SUM(CASE WHEN (estado_reserva='PENDIENTE' OR estado_reserva IS NULL) THEN 1 ELSE 0 END) as pendiente,
    SUM(CASE WHEN (estado_reserva='PENDIENTE' OR estado_reserva IS NULL) AND DATE(fecha_reserva)=CURDATE() THEN 1 ELSE 0 END) as hoy,
    SUM(CASE WHEN (estado_reserva='PENDIENTE' OR estado_reserva IS NULL) AND fecha_reserva < NOW() THEN 1 ELSE 0 END) as vencidas,
    SUM(CASE WHEN estado_pago='verificando' AND (estado_reserva='PENDIENTE' OR estado_reserva IS NULL) THEN 1 ELSE 0 END) as verificando,
    SUM(CASE WHEN (estado_reserva='PENDIENTE' OR estado_reserva IS NULL) AND sin_existencia=1 THEN 1 ELSE 0 END) as sin_stock
    FROM ventas_cabecera WHERE tipo_servicio='reserva' AND id_sucursal=?");
$stmtKpi->execute([$sucursalID]);
$kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: ['pendiente'=>0,'hoy'=>0,'vencidas'=>0,'verificando'=>0,'sin_stock'=>0];

// Total y páginas
$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM ventas_cabecera c WHERE $whereSQL");
$stmtCnt->execute($params);
$totalCount = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, ceil($totalCount / $perPage));

// Datos
$sqlMain = "SELECT c.id, c.cliente_nombre, c.cliente_telefono, c.cliente_direccion,
    c.fecha_reserva, c.total, c.abono,
    (c.total - COALESCE(c.abono,0)) AS deuda,
    c.notas, c.metodo_pago, c.estado_reserva,
    COALESCE(c.estado_pago,'pendiente') AS estado_pago,
    c.codigo_pago, COALESCE(c.sin_existencia,0) AS sin_existencia, c.id_cliente,
    COALESCE(c.canal_origen,'POS') AS canal_origen,
    COUNT(d.id) AS num_items,
    GROUP_CONCAT(CONCAT(FORMAT(d.cantidad,0),'× ',d.nombre_producto) SEPARATOR ', ') AS resumen_items,
    (SELECT COUNT(*) FROM comandas com WHERE com.id_venta=c.id) AS en_cocina
    FROM ventas_cabecera c
    LEFT JOIN ventas_detalle d ON c.id=d.id_venta_cabecera
    WHERE $whereSQL
    GROUP BY c.id
    ORDER BY c.fecha_reserva ASC
    LIMIT $perPage OFFSET $offset";
$stmtMain = $pdo->prepare($sqlMain);
$stmtMain->execute($params);
$reservas = $stmtMain->fetchAll(PDO::FETCH_ASSOC);

// ── Closure para renderizar filas (usado en AJAX y HTML) ──────────────────────
$renderRows = function() use ($reservas) {
    $now = new DateTime();
    foreach ($reservas as $r):
        $fechaRes = $r['fecha_reserva'] ? new DateTime($r['fecha_reserva']) : null;
        $dias     = $fechaRes ? (int)$now->diff($fechaRes)->format($now > $fechaRes ? '-%a' : '%a') : 0;
        $esHoy    = $fechaRes && $fechaRes->format('Y-m-d') === $now->format('Y-m-d');
        $vencida  = $fechaRes && $now > $fechaRes && !$esHoy;

        // Badge tiempo
        if ($esHoy)           { $tbCls = 'bg-warning text-dark'; $tbTxt = 'HOY'; }
        elseif ($dias > 0)    { $tbCls = 'bg-primary';           $tbTxt = "En $dias d."; }
        elseif ($vencida)     { $tbCls = 'bg-danger';            $tbTxt = abs($dias).'d vencida'; }
        else                  { $tbCls = 'bg-secondary';         $tbTxt = '—'; }

        // Badge estado reserva
        $er = $r['estado_reserva'] ?? 'PENDIENTE';
        if ($er === 'ENTREGADO')         { $erCls = 'bg-success';           $erTxt = '✓ Entregado'; }
        elseif ($er === 'CANCELADO')     { $erCls = 'bg-secondary';         $erTxt = '✗ Cancelado'; }
        elseif ($er === 'EN_CAMINO')     { $erCls = 'bg-primary';           $erTxt = '🛵 En Camino'; }
        elseif ($er === 'EN_PREPARACION'){ $erCls = 'bg-info text-dark';    $erTxt = '🔥 En Preparación'; }
        else                             { $erCls = 'bg-warning text-dark'; $erTxt = '⏳ Pendiente'; }

        // Badge pago
        $ep = $r['estado_pago'];
        if ($ep === 'confirmado')     { $epCls = 'bg-success';        $epTxt = '✓ Pagado'; }
        elseif ($ep === 'verificando'){ $epCls = 'bg-warning text-dark'; $epTxt = '💳 Verificando'; }
        else                          { $epCls = 'bg-light text-dark border'; $epTxt = 'Al recibir'; }

        $rowCls = $vencida ? 'table-danger' : ($esHoy ? 'table-warning' : '');
        $deuda  = floatval($r['deuda']);
        $isPending = ($er === 'PENDIENTE' || !$er);

        // Badge canal de origen
        $canal = $r['canal_origen'] ?? 'POS';
        $canalMap = [
            'POS'        => ['background-color:#f97316!important;color:#000!important', 'fas fa-cash-register', 'POS'],
            'Web'        => ['background-color:#fde047!important;color:#000!important', 'fas fa-globe',         'Web'],
            'WhatsApp'   => ['background-color:#1d4ed8!important;color:#fff!important', 'fab fa-whatsapp',      'WhatsApp'],
            'Teléfono'   => ['background-color:#fbcfe8!important;color:#000!important', 'fas fa-phone-alt',     'Tel.'],
            'Kiosko'     => ['background-color:#fbcfe8!important;color:#000!important', 'fas fa-tablet-alt',    'Kiosko'],
            'Presencial' => ['background-color:#fbcfe8!important;color:#000!important', 'fas fa-user',          'Presencial'],
            'ICS'        => ['background-color:#fbcfe8!important;color:#000!important', 'fas fa-file-import',   'ICS'],
            'Otro'       => ['background-color:#fbcfe8!important;color:#000!important', 'fas fa-question',      'Otro'],
        ];
        [$cBg, $cIcon, $cLabel] = $canalMap[$canal] ?? $canalMap['Otro'];
?>
<tr class="<?= $rowCls ?>">
    <td class="ps-3 small fw-bold text-muted">#<?= $r['id'] ?></td>
    <td>
        <div class="fw-bold text-dark"><?= htmlspecialchars($r['cliente_nombre']) ?></div>
        <?php if ($r['cliente_telefono']): ?>
        <div class="small text-muted"><i class="fas fa-phone-alt me-1"></i><?= htmlspecialchars($r['cliente_telefono']) ?></div>
        <?php endif; ?>
        <?php if ($r['sin_existencia']): ?>
        <span class="badge bg-danger" style="font-size:.6rem;">📦 Sin stock</span>
        <?php endif; ?>
    </td>
    <td class="small">
        <?php if ($fechaRes): ?>
        <div class="fw-bold"><?= $fechaRes->format('d/m/Y') ?></div>
        <div class="text-muted"><?= $fechaRes->format('h:i A') ?></div>
        <?php endif; ?>
        <span class="badge <?= $tbCls ?> mt-1"><?= $tbTxt ?></span>
    </td>
    <td class="small text-muted" style="max-width:180px;">
        <span title="<?= htmlspecialchars($r['resumen_items'] ?? '') ?>">
            <?= htmlspecialchars(mb_strimwidth($r['resumen_items'] ?? 'Sin items', 0, 55, '…')) ?>
        </span>
        <?php if ($r['en_cocina']): ?>
        <div><span class="badge bg-info text-dark mt-1" style="font-size:.6rem;"><i class="fas fa-fire-alt me-1"></i>Cocina</span></div>
        <?php endif; ?>
    </td>
    <td class="text-end">
        <div class="fw-bold">$<?= number_format($r['total'], 2) ?></div>
        <?php if ($deuda > 0): ?>
        <div class="small text-danger fw-bold">Debe: $<?= number_format($deuda, 2) ?></div>
        <?php else: ?>
        <div class="small text-success">✓ Saldado</div>
        <?php endif; ?>
    </td>
    <td class="text-center">
        <span class="badge <?= $epCls ?>"><?= $epTxt ?></span>
        <?php if ($ep === 'verificando' && $r['codigo_pago']): ?>
        <div class="small text-muted mt-1">Cód: <?= htmlspecialchars($r['codigo_pago']) ?></div>
        <?php endif; ?>
    </td>
    <td class="text-center"><span class="badge <?= $erCls ?>"><?= $erTxt ?></span></td>
    <td class="text-center">
        <span style="display:inline-flex;align-items:center;gap:4px;<?= $cBg ?>;padding:4px 10px;border-radius:20px;font-size:.68rem;font-weight:800;white-space:nowrap;print-color-adjust:exact;-webkit-print-color-adjust:exact;">
            <i class="<?= $cIcon ?>"></i><?= $cLabel ?>
        </span>
    </td>
    <td class="text-end pe-3 no-print">
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-primary" title="Gestionar estado"
                onclick="openGestionarEstado(<?= $r['id'] ?>,'<?= $er ?>',`<?= addslashes($r['notas'] ?? '') ?>`)">
                <i class="fas fa-tasks"></i></button>
            <button class="btn btn-outline-secondary" onclick="verTicket(<?= $r['id'] ?>,<?= $deuda ?>)" title="Ver ticket"><i class="fas fa-eye"></i></button>
            <?php if ($isPending): ?>
            <button class="btn btn-outline-primary" onclick="openForm(<?= $r['id'] ?>)" title="Editar"><i class="fas fa-edit"></i></button>
            <?php if ($ep === 'verificando'): ?>
            <button class="btn btn-outline-success fw-bold" onclick="confirmarPago(<?= $r['id'] ?>)" title="Confirmar transferencia"><i class="fas fa-check-double"></i></button>
            <button class="btn btn-outline-danger fw-bold" onclick="rechazarPago(<?= $r['id'] ?>)" title="Rechazar transferencia"><i class="fas fa-ban"></i></button>
            <?php endif; ?>
            <?php if ($esHoy && !$r['en_cocina']): ?>
            <button class="btn btn-outline-info" onclick="enviarACocina(<?= $r['id'] ?>)" title="Enviar a cocina"><i class="fas fa-fire-alt"></i></button>
            <?php endif; ?>
            <button class="btn btn-outline-success fw-bold" onclick="procesarAccion(<?= $r['id'] ?>,'complete')" title="Entregar"><i class="fas fa-check-circle"></i></button>
            <button class="btn btn-outline-danger" onclick="procesarAccion(<?= $r['id'] ?>,'cancel')" title="Cancelar"><i class="fas fa-times-circle"></i></button>
            <?php else: ?>
            <button class="btn btn-outline-secondary" title="Imprimir" onclick="window.open('ticket_view.php?id=<?= $r['id'] ?>','_blank','width=380,height=600')"><i class="fas fa-print"></i></button>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach;
};

// ── Respuesta AJAX (solo filas) ───────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    ob_start(); $renderRows(); $html = ob_get_clean();
    echo json_encode(['html' => $html, 'total' => $totalCount, 'pages' => $totalPages, 'page' => $page]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Reservas — Sucursal <?= $sucursalID ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background:#f1f5f9; font-family:'Segoe UI',sans-serif; color:#1e293b; padding-bottom:80px; }
        .navbar-custom { background:#0f172a; padding:.9rem 1.5rem; border-bottom:3px solid #6366f1; }
        .kpi-card { border-radius:14px; padding:18px 20px; background:white; box-shadow:0 2px 8px rgba(0,0,0,.07); border-left:4px solid; }
        .kpi-num  { font-size:2rem; font-weight:900; line-height:1; }
        .kpi-lbl  { font-size:.78rem; color:#64748b; margin-top:4px; font-weight:600; }
        .filter-bar { background:white; border-radius:12px; padding:14px 18px; box-shadow:0 2px 6px rgba(0,0,0,.06); }
        .card-table { border:none; border-radius:12px; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,.08); }
        .table thead th { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; background:#f8fafc; padding:12px; white-space:nowrap; }
        .table tbody tr { transition:background .15s; }
        .btn-group .btn { padding:.25rem .45rem; font-size:.75rem; }
        /* Dropdown de búsqueda */
        .search-dropdown { position:absolute; z-index:1050; background:white; border:1px solid #d1d5db;
            border-radius:10px; box-shadow:0 8px 25px rgba(0,0,0,.12); width:100%; max-height:220px; overflow-y:auto; }
        .search-dropdown .item { padding:10px 14px; cursor:pointer; font-size:.88rem; border-bottom:1px solid #f3f4f6; }
        .search-dropdown .item:hover { background:#eff6ff; }
        .search-dropdown .item:last-child { border-bottom:none; }
        /* Lines table */
        #linesTable td,#linesTable th { padding:6px 10px; vertical-align:middle; }
        .modal-xl { max-width:960px; }
        @media print { .no-print { display:none!important; } body { background:white; } }
        .crm-badge-ok  { background:#dcfce7; color:#166534; border:1px solid #86efac; padding:2px 8px; border-radius:20px; font-size:.7rem; font-weight:700; display:inline-flex; align-items:center; gap:5px; }
        .crm-badge-no  { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; padding:2px 8px; border-radius:20px; font-size:.7rem; font-weight:700; display:inline-flex; align-items:center; gap:5px; }
        .crm-badge-no .btn-guardar-cliente { background:none; border:none; padding:0; font-size:.7rem; font-weight:700; color:#0d6efd; cursor:pointer; text-decoration:underline; }
        /* Reporte imprimible */
        .print-section { display:none; }
        @media print { .print-section { display:block; } }

        /* ── Almanaque ─────────────────────────────────────────────────────── */
        .cal-wrapper { background:white; border-radius:14px; box-shadow:0 4px 16px rgba(0,0,0,.08); overflow:hidden; }
        .cal-nav { background:#0f172a; color:white; padding:14px 20px; display:flex; align-items:center; justify-content:space-between; }
        .cal-nav h5 { margin:0; font-size:1.15rem; font-weight:800; letter-spacing:.02em; }
        .cal-nav .btn { border-radius:50%; width:36px; height:36px; padding:0; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
        .cal-legend { display:flex; flex-wrap:wrap; gap:10px; padding:10px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:.75rem; }
        .cal-legend-item { display:flex; align-items:center; gap:5px; font-weight:600; color:#374151; }
        .cal-legend-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .cal-day-names { display:grid; grid-template-columns:repeat(7,1fr); background:#f1f5f9; border-bottom:1px solid #e2e8f0; }
        .cal-day-name { padding:8px 4px; text-align:center; font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#64748b; }
        .cal-day-name:last-child, .cal-day-name:nth-child(6) { color:#ef4444; } /* Sab/Dom rojo */
        .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:1px; background:#e2e8f0; }
        .cal-day { background:white; min-height:90px; padding:6px 7px; display:flex; flex-direction:column; gap:3px; transition:background .15s; }
        .cal-day:hover { background:#f8fafc; }
        .cal-day.other-month { background:#f9fafb; }
        .cal-day.other-month .cal-day-num { color:#cbd5e1; }
        .cal-day.cal-today { background:#fffbeb; }
        .cal-day.cal-today .cal-day-num { background:#f59e0b; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:.8rem; }
        .cal-day-num { font-size:.8rem; font-weight:700; color:#374151; line-height:1; margin-bottom:2px; }
        .cal-day.has-reservas { cursor:default; }
        .cal-reservation {
            font-size:.68rem; font-weight:700; padding:2px 6px; border-radius:5px;
            cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
            max-width:100%; transition:opacity .15s;
        }
        .cal-reservation:hover { opacity:.8; }
        .cal-res-pendiente { background:#dbeafe; color:#1d4ed8; border-left:3px solid #3b82f6; }
        .cal-res-hoy       { background:#fef9c3; color:#854d0e; border-left:3px solid #f59e0b; }
        .cal-res-vencida   { background:#fee2e2; color:#991b1b; border-left:3px solid #ef4444; }
        .cal-res-entregado { background:#dcfce7; color:#166534; border-left:3px solid #22c55e; }
        .cal-res-cancelado { background:#f1f5f9; color:#64748b; border-left:3px solid #94a3b8; text-decoration:line-through; }
        .cal-more { background:#f1f5f9; color:#6366f1; border-left:3px solid #6366f1; cursor:pointer; }
        /* Vista activa */
        .view-btn.active { background:#0f172a !important; color:white !important; border-color:#0f172a !important; }
        @media(max-width:640px) {
            .cal-day { min-height:60px; padding:4px; }
            .cal-reservation { font-size:.6rem; padding:1px 4px; }
        }
    </style>
</head>
<body>

<!-- ── Navbar ─────────────────────────────────────────────────────────────── -->
<nav class="navbar-custom d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div class="d-flex align-items-center gap-3">
        <h5 class="text-white fw-bold m-0"><i class="far fa-calendar-check me-2 text-indigo-400"></i>Gestión de Reservas</h5>
        <span class="badge bg-indigo-600" style="background:#6366f1;">Suc. <?= $sucursalID ?></span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="crm_clients.php"  class="btn btn-outline-light btn-sm"><i class="fas fa-users me-1"></i>CRM Clientes</a>
        <a href="dashboard.php"    class="btn btn-outline-light btn-sm"><i class="fas fa-home me-1"></i>Dashboard</a>
        <a href="guia_reservas.php" class="btn btn-outline-light btn-sm" target="_blank"><i class="fas fa-book-open me-1"></i>Guía</a>
        <button class="btn btn-outline-info btn-sm" onclick="printReport()"><i class="fas fa-print me-1"></i>Reporte Stock</button>
        <button class="btn btn-outline-warning btn-sm" onclick="importICS()"><i class="fas fa-file-import me-1"></i>Importar .ICS</button>
        <button class="btn btn-primary btn-sm fw-bold px-3" onclick="openForm(null)"><i class="fas fa-plus me-1"></i>Nueva Reserva</button>
    </div>
</nav>

<div class="container-fluid px-4 pt-4">

    <!-- ── Alerta sin stock ──────────────────────────────────────────────────── -->
    <?php if (intval($kpis['sin_stock']) > 0): ?>
    <div class="alert alert-danger d-flex align-items-center mb-3 border-0 shadow-sm" role="alert">
        <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
        <strong><?= $kpis['sin_stock'] ?> reserva<?= $kpis['sin_stock']>1?'s':'' ?> con productos sin existencias.</strong>
        <span class="ms-2 text-muted small">Revisa las marcadas en rojo antes de confirmar entrega.</span>
    </div>
    <?php endif; ?>

    <!-- ── KPIs ─────────────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-6 col-md-3">
            <div class="kpi-card" style="border-color:#6366f1">
                <div class="kpi-num text-indigo" style="color:#6366f1"><?= intval($kpis['pendiente']) ?></div>
                <div class="kpi-lbl">📋 Pendientes</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card" style="border-color:#f59e0b">
                <div class="kpi-num" style="color:#f59e0b"><?= intval($kpis['hoy']) ?></div>
                <div class="kpi-lbl">📅 Para hoy</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card" style="border-color:#ef4444">
                <div class="kpi-num" style="color:#ef4444"><?= intval($kpis['vencidas']) ?></div>
                <div class="kpi-lbl">⏰ Vencidas</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card" style="border-color:#22c55e">
                <div class="kpi-num" style="color:#22c55e"><?= intval($kpis['verificando']) ?></div>
                <div class="kpi-lbl">💳 Verificando pago</div>
            </div>
        </div>
    </div>

    <!-- ── Barra de filtros ─────────────────────────────────────────────────── -->
    <div class="filter-bar mb-3 no-print">
        <div class="row g-2 align-items-center">
            <!-- Toggle de vista -->
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-secondary view-btn active" id="btnVistaTbl" onclick="setVista('tabla')"><i class="fas fa-list me-1"></i>Lista</button>
                    <button class="btn btn-outline-secondary view-btn" id="btnVistaCal" onclick="setVista('almanaque')"><i class="fas fa-calendar-alt me-1"></i>Almanaque</button>
                </div>
            </div>
            <div class="col-auto"><div class="vr"></div></div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <?php foreach (['PENDIENTE'=>'⏳ Pendientes','VERIFICANDO'=>'💳 Por Verificar','ENTREGADO'=>'✓ Entregados','CANCELADO'=>'✗ Cancelados','TODOS'=>'Todos'] as $val=>$lbl): ?>
                    <button type="button" class="btn <?= $filterEstado===$val?'btn-dark':'btn-outline-secondary' ?>"
                            onclick="setFiltro('estado','<?= $val ?>')"><?= $lbl ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm">
                    <?php foreach ([''=> 'Todas las fechas','hoy'=>'Hoy','semana'=>'Próx. 7 días','vencidas'=>'Vencidas'] as $val=>$lbl): ?>
                    <button type="button" class="btn <?= $filterFecha===$val?'btn-dark':'btn-outline-secondary' ?>"
                            onclick="setFiltro('fecha','<?= $val ?>')"><?= $lbl ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" id="searchQ" class="form-control" placeholder="Buscar por cliente..." value="<?= htmlspecialchars($filterQ) ?>" onkeyup="if(event.key==='Enter')setFiltro('q',this.value)">
                    <button class="btn btn-outline-secondary" onclick="setFiltro('q',document.getElementById('searchQ').value)">Buscar</button>
                    <?php if ($filterQ || $filterFecha || $filterEstado!=='PENDIENTE'): ?>
                    <a class="btn btn-outline-danger" href="reservas.php">✕ Limpiar</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-auto text-muted small">
                Total: <strong id="totalCount"><?= $totalCount ?></strong>
            </div>
        </div>
    </div>

    <!-- ── Tabla principal ─────────────────────────────────────────────────── -->
    <div id="tableSection">
    <div class="card card-table shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">ID</th>
                        <th>Cliente</th>
                        <th>Fecha Reserva</th>
                        <th>Productos</th>
                        <th class="text-end">Total / Deuda</th>
                        <th class="text-center">Pago</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center">Origen</th>
                        <th class="text-end pe-3 no-print">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php $renderRows(); ?>
                    <?php if (empty($reservas)): ?>
                    <tr><td colspan="9" class="text-center py-5 text-muted">
                        <i class="far fa-calendar-times fa-3x mb-3 d-block opacity-25"></i>
                        No hay reservas con estos filtros.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Paginación -->
        <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white d-flex justify-content-center py-2 no-print">
            <nav><ul class="pagination pagination-sm mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item"><button class="page-link" onclick="goPage(<?= $page-1 ?>)">Anterior</button></li>
                <?php endif; ?>
                <li class="page-item disabled"><span class="page-link">Pág <?= $page ?>/<?= $totalPages ?></span></li>
                <?php if ($page < $totalPages): ?>
                <li class="page-item"><button class="page-link" onclick="goPage(<?= $page+1 ?>)">Siguiente</button></li>
                <?php endif; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
    </div><!-- /#tableSection -->

    <!-- ── Almanaque ──────────────────────────────────────────────────────── -->
    <div id="calView" style="display:none;" class="mt-0">
        <div class="cal-wrapper">
            <div class="cal-nav">
                <button class="btn btn-outline-light btn-sm" onclick="prevMonth()"><i class="fas fa-chevron-left"></i></button>
                <h5 id="calTitle" class="mb-0">Cargando...</h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-warning btn-sm" onclick="goToday()">Hoy</button>
                    <button class="btn btn-outline-light btn-sm" onclick="nextMonth()"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
            <div class="cal-legend">
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#3b82f6;"></div>Pendiente</div>
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#f59e0b;"></div>Para hoy</div>
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#ef4444;"></div>Vencida</div>
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#22c55e;"></div>Entregado</div>
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#94a3b8;"></div>Cancelado</div>
            </div>
            <div class="cal-day-names">
                <div class="cal-day-name">Lun</div>
                <div class="cal-day-name">Mar</div>
                <div class="cal-day-name">Mié</div>
                <div class="cal-day-name">Jue</div>
                <div class="cal-day-name">Vie</div>
                <div class="cal-day-name">Sáb</div>
                <div class="cal-day-name">Dom</div>
            </div>
            <div class="cal-grid" id="calGrid"></div>
        </div>
    </div>

</div><!-- /container -->

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL: CREAR / EDITAR RESERVA
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="formModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="formModalTitle"><i class="fas fa-calendar-plus me-2"></i>Nueva Reserva</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="fId">
                <div class="row g-4">
                    <!-- ── Columna izquierda: Cliente + Detalles ─────────────── -->
                    <div class="col-lg-4">
                        <!-- Cliente -->
                        <div class="card border-0 bg-light rounded-3 mb-3">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold mb-0"><i class="fas fa-user me-1 text-primary"></i>Cliente</h6>
                                    <button class="btn btn-outline-success btn-sm" onclick="abrirNuevoCliente()"><i class="fas fa-user-plus me-1"></i>Nuevo</button>
                                </div>
                                <!-- Búsqueda autocomplete -->
                                <div class="position-relative mb-2">
                                    <input type="text" id="clientSearch" class="form-control form-control-sm"
                                           placeholder="Buscar cliente existente..." autocomplete="off"
                                           oninput="debounceClientSearch(this.value)">
                                    <div id="clientResults" class="search-dropdown" style="display:none;"></div>
                                </div>
                                <input type="hidden" id="fIdCliente">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1">Nombre *</label>
                                    <input type="text" id="fClienteNombre" class="form-control form-control-sm" placeholder="Nombre completo">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1">Teléfono</label>
                                    <input type="text" id="fClienteTel" class="form-control form-control-sm" placeholder="+53 5 000 0000"
                                           oninput="debounceCheckCliente(this.value)">
                                    <div id="clienteCrmBadge" class="mt-1" style="min-height:22px;"></div>
                                </div>
                                <div>
                                    <label class="form-label small fw-bold mb-1">Dirección de entrega</label>
                                    <input type="text" id="fClienteDir" class="form-control form-control-sm" placeholder="Calle, número, municipio">
                                </div>
                                <div class="mt-2 text-end">
                                    <a href="crm_clients.php" target="_blank" class="small text-primary"><i class="fas fa-external-link-alt me-1"></i>Ver todos en CRM</a>
                                </div>
                            </div>
                        </div>
                        <!-- Detalles de la reserva -->
                        <div class="card border-0 bg-light rounded-3">
                            <div class="card-body p-3">
                                <h6 class="fw-bold mb-3"><i class="fas fa-sliders-h me-1 text-primary"></i>Detalles</h6>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1">Fecha y hora de entrega *</label>
                                    <input type="datetime-local" id="fFechaReserva" class="form-control form-control-sm">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1">Canal de origen</label>
                                    <select id="fCanalOrigen" class="form-select form-select-sm">
                                        <option value="WhatsApp">💬 WhatsApp</option>
                                        <option value="POS">🖥️ POS</option>
                                        <option value="Web">🌐 Web</option>
                                        <option value="Teléfono">📞 Teléfono</option>
                                        <option value="Kiosko">📱 Kiosko</option>
                                        <option value="Presencial">🙋 Presencial</option>
                                        <option value="Otro">❓ Otro</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1">Método de pago</label>
                                    <select id="fMetodoPago" class="form-select form-select-sm">
                                        <option value="Efectivo">💵 Efectivo</option>
                                        <option value="Transferencia">📲 Transferencia</option>
                                        <option value="Pendiente">⏳ Pendiente</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1">Abono recibido ($)</label>
                                    <input type="number" id="fAbono" class="form-control form-control-sm" placeholder="0.00" step="0.01" min="0">
                                </div>
                                <div>
                                    <label class="form-label small fw-bold mb-1">Notas internas</label>
                                    <textarea id="fNotas" class="form-control form-control-sm" rows="3" placeholder="Instrucciones especiales, color de pastel, dedicatoria…"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Columna derecha: Productos ────────────────────────── -->
                    <div class="col-lg-8">
                        <div class="card border-0 bg-light rounded-3 h-100">
                            <div class="card-body p-3">
                                <h6 class="fw-bold mb-3"><i class="fas fa-boxes me-1 text-primary"></i>Productos del pedido</h6>
                                <!-- Buscador de producto -->
                                <div class="position-relative mb-3">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input type="text" id="prodSearch" class="form-control" placeholder="Buscar producto por nombre o SKU…" autocomplete="off" oninput="debounceProdSearch(this.value)">
                                    </div>
                                    <div id="prodResults" class="search-dropdown" style="display:none;"></div>
                                </div>
                                <!-- Tabla de líneas -->
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0" id="linesTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Producto</th>
                                                <th style="width:80px">Cant.</th>
                                                <th style="width:95px">P. Unit</th>
                                                <th style="width:95px">Subtotal</th>
                                                <th style="width:36px"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="linesTbody">
                                            <tr id="emptyLinesRow"><td colspan="5" class="text-center text-muted small py-4">
                                                <i class="fas fa-shopping-basket fa-2x mb-2 d-block opacity-25"></i>Busca productos para agregarlos al pedido
                                            </td></tr>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-primary fw-bold">
                                                <td colspan="3" class="text-end">TOTAL:</td>
                                                <td id="totalDisplay" class="fw-bold" style="font-size:1.05rem;">$0.00</td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary px-4 fw-bold" onclick="saveReserva()">
                    <i class="fas fa-save me-2"></i>Guardar Reserva
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL: VER TICKET
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="ticketModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold"><i class="fas fa-receipt me-2"></i>Reserva #<span id="tModalId"></span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="ticketFrame" src="" style="width:100%;height:370px;border:none;"></iframe>
                <div class="p-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge p-2 fs-6" id="tDeudaBadge"></span>
                        <button class="btn btn-sm btn-outline-secondary" onclick="window.open('ticket_view.php?id='+tCurrentId,'_blank','width=380,height=600')">
                            <i class="fas fa-print me-1"></i>Imprimir
                        </button>
                    </div>
                    <div class="d-grid gap-2 d-flex" id="tActions"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL: GESTIONAR ESTADO
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="gestionarEstadoModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title fw-bold"><i class="fas fa-tasks me-2"></i>Gestionar Reserva <span id="gModalId"></span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <input type="hidden" id="gId">
                <div class="mb-3">
                    <label class="form-label fw-bold small">Estado del pedido</label>
                    <select class="form-select" id="gEstado">
                        <option value="PENDIENTE">⏳ Pendiente</option>
                        <option value="EN_PREPARACION">🔥 En Preparación</option>
                        <option value="EN_CAMINO">🛵 En Camino</option>
                        <option value="ENTREGADO">✅ Entregado</option>
                        <option value="CANCELADO">❌ Cancelado</option>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-bold small">Nota interna</label>
                    <textarea class="form-control form-control-sm" id="gNota" rows="2" placeholder="Ej: Motorista Juan en camino…"></textarea>
                </div>
            </div>
            <div class="modal-footer p-2">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary fw-bold flex-fill" onclick="saveEstado()"><i class="fas fa-save me-1"></i>Guardar Estado</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL: NUEVO CLIENTE RÁPIDO
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="newClientModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i>Nuevo Cliente</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="mb-2">
                    <label class="form-label small fw-bold mb-1">Nombre *</label>
                    <input type="text" id="ncNombre" class="form-control form-control-sm" placeholder="Nombre completo">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold mb-1">Teléfono</label>
                    <input type="text" id="ncTel" class="form-control form-control-sm" placeholder="+53 5 000 0000">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold mb-1">Dirección</label>
                    <input type="text" id="ncDir" class="form-control form-control-sm" placeholder="Dirección">
                </div>
                <div>
                    <label class="form-label small fw-bold mb-1">Categoría</label>
                    <select id="ncCat" class="form-select form-select-sm">
                        <option value="Regular">Regular</option>
                        <option value="VIP">⭐ VIP</option>
                        <option value="Corporativo">🏢 Corporativo</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer p-2">
                <button class="btn btn-success w-100 fw-bold" onclick="guardarNuevoCliente()"><i class="fas fa-save me-1"></i>Crear Cliente</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="toastEl" class="toast align-items-center border-0 text-white" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-bold" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<?php include_once 'menu_master.php'; ?>
<footer class="text-center text-muted py-3" style="font-size:.72rem;">Sistema PALWEB POS v3.0</footer>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
// ── Estado global ──────────────────────────────────────────────────────────
let lineItems    = [];
let currentEstado = '<?= $filterEstado ?>';
let currentFecha  = '<?= $filterFecha ?>';
let currentQ      = '<?= addslashes($filterQ) ?>';
let currentPage   = <?= $page ?>;
let tCurrentId    = 0;

const formModal           = new bootstrap.Modal('#formModal');
const ticketModal         = new bootstrap.Modal('#ticketModal');
const newClientModal      = new bootstrap.Modal('#newClientModal');
const gestionarEstadoModal = new bootstrap.Modal('#gestionarEstadoModal');
const toastEl             = new bootstrap.Toast(document.getElementById('toastEl'), {delay:3000});

function showToast(msg, type='success') {
    const el = document.getElementById('toastEl');
    el.className = `toast align-items-center border-0 text-white bg-${type}`;
    document.getElementById('toastMsg').innerText = msg;
    toastEl.show();
}

// ── Navegación / filtros ────────────────────────────────────────────────────
function setFiltro(key, val) {
    if (key === 'estado') currentEstado = val;
    if (key === 'fecha')  currentFecha  = val;
    if (key === 'q')      currentQ      = val;
    currentPage = 1;
    reloadTable();
}
function goPage(p) { currentPage = p; reloadTable(); }

async function reloadTable() {
    const url = `reservas.php?ajax=1&estado=${currentEstado}&fecha=${currentFecha}&q=${encodeURIComponent(currentQ)}&page=${currentPage}`;
    document.getElementById('tableBody').style.opacity = '.4';
    const res  = await fetch(url);
    const data = await res.json();
    document.getElementById('tableBody').innerHTML = data.html || '<tr><td colspan="8" class="text-center py-5 text-muted">Sin resultados.</td></tr>';
    document.getElementById('tableBody').style.opacity = '1';
    document.getElementById('totalCount').innerText = data.total;
}

// ── Abrir formulario crear/editar ───────────────────────────────────────────
async function openForm(id) {
    resetForm();
    if (id) {
        document.getElementById('formModalTitle').innerHTML = `<i class="fas fa-edit me-2"></i>Editar Reserva #${id}`;
        const res  = await fetch('reservas.php', {method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'get_detail', id})});
        const data = await res.json();
        if (data.status !== 'success') { showToast('Error cargando reserva', 'danger'); return; }
        const d = data.data;
        document.getElementById('fId').value          = d.id;
        document.getElementById('fIdCliente').value   = d.id_cliente || '';
        document.getElementById('fClienteNombre').value = d.cliente_nombre || '';
        document.getElementById('fClienteTel').value  = d.cliente_telefono || '';
        document.getElementById('fClienteDir').value  = d.cliente_direccion || '';
        document.getElementById('fAbono').value        = d.abono || '';
        document.getElementById('fNotas').value        = d.notas || '';
        document.getElementById('fMetodoPago').value   = d.metodo_pago || 'Efectivo';
        document.getElementById('fCanalOrigen').value  = d.canal_origen || 'WhatsApp';
        // Verificar si cliente existe en CRM
        if (d.cliente_telefono) checkClienteByTel(d.cliente_telefono);
        if (d.fecha_reserva) {
            const dt = new Date(d.fecha_reserva.replace(' ','T'));
            document.getElementById('fFechaReserva').value =
                dt.toISOString().slice(0,16);
        }
        (d.items || []).forEach(it => addLine({...it, stock: 99}));
    } else {
        document.getElementById('formModalTitle').innerHTML = '<i class="fas fa-calendar-plus me-2"></i>Nueva Reserva';
        // Default: mañana a mediodía
        const tom = new Date(Date.now() + 86400000);
        tom.setHours(12,0,0,0);
        document.getElementById('fFechaReserva').value = tom.toISOString().slice(0,16);
    }
    formModal.show();
}

function resetForm() {
    lineItems = [];
    document.getElementById('fId').value            = '';
    document.getElementById('fIdCliente').value     = '';
    document.getElementById('fClienteNombre').value = '';
    document.getElementById('fClienteTel').value    = '';
    document.getElementById('fClienteDir').value    = '';
    document.getElementById('fAbono').value         = '';
    document.getElementById('fNotas').value         = '';
    document.getElementById('fMetodoPago').value    = 'Efectivo';
    document.getElementById('fCanalOrigen').value   = 'WhatsApp';
    document.getElementById('fFechaReserva').value  = '';
    document.getElementById('clientSearch').value   = '';
    document.getElementById('prodSearch').value     = '';
    document.getElementById('clienteCrmBadge').innerHTML = '';
    renderLines();
}

// ── Guardar reserva ─────────────────────────────────────────────────────────
async function saveReserva() {
    const nombre = document.getElementById('fClienteNombre').value.trim();
    const fecha  = document.getElementById('fFechaReserva').value;
    if (!nombre)       { showToast('El nombre del cliente es requerido.', 'warning'); return; }
    if (!fecha)        { showToast('La fecha de entrega es requerida.', 'warning'); return; }
    if (!lineItems.length) { showToast('Agrega al menos un producto.', 'warning'); return; }

    const id = document.getElementById('fId').value;
    const payload = {
        action:           id ? 'update' : 'create',
        id:               id ? parseInt(id) : undefined,
        cliente_nombre:   nombre,
        cliente_telefono: document.getElementById('fClienteTel').value.trim(),
        cliente_direccion:document.getElementById('fClienteDir').value.trim(),
        id_cliente:       parseInt(document.getElementById('fIdCliente').value) || 0,
        fecha_reserva:    fecha.replace('T',' ') + ':00',
        notas:            document.getElementById('fNotas').value.trim(),
        metodo_pago:      document.getElementById('fMetodoPago').value,
        canal_origen:     document.getElementById('fCanalOrigen').value,
        abono:            parseFloat(document.getElementById('fAbono').value) || 0,
        items:            lineItems.map(({codigo,nombre,categoria,cantidad,precio}) => ({codigo,nombre,categoria,cantidad,precio}))
    };

    const res  = await fetch('reservas.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const data = await res.json();
    if (data.status === 'success') {
        showToast(id ? '✓ Reserva actualizada.' : `✓ Reserva #${data.id} creada.`);
        formModal.hide();
        reloadTable();
    } else {
        showToast('Error: ' + data.msg, 'danger');
    }
}

// ── Líneas de producto ──────────────────────────────────────────────────────
function addLine(prod) {
    const existing = lineItems.findIndex(i => i.codigo === prod.codigo);
    if (existing >= 0) { lineItems[existing].cantidad += 1; }
    else lineItems.push({ codigo: prod.codigo, nombre: prod.nombre, categoria: prod.categoria || 'General', cantidad: 1, precio: parseFloat(prod.precio) });
    renderLines();
    document.getElementById('prodSearch').value = '';
    document.getElementById('prodResults').style.display = 'none';
}
function removeLine(idx) { lineItems.splice(idx, 1); renderLines(); }
function updateLine(idx, field, val) {
    lineItems[idx][field] = parseFloat(val) || 0;
    renderLines();
}
function renderLines() {
    const tbody = document.getElementById('linesTbody');
    const empty = document.getElementById('emptyLinesRow');
    if (!lineItems.length) {
        tbody.innerHTML = '';
        const tr = document.createElement('tr');
        tr.id = 'emptyLinesRow';
        tr.innerHTML = '<td colspan="5" class="text-center text-muted small py-4"><i class="fas fa-shopping-basket fa-2x mb-2 d-block opacity-25"></i>Busca productos para agregarlos</td>';
        tbody.appendChild(tr);
        document.getElementById('totalDisplay').innerText = '$0.00';
        return;
    }
    let total = 0;
    tbody.innerHTML = lineItems.map((it, idx) => {
        const sub = it.cantidad * it.precio;
        total += sub;
        return `<tr>
            <td>
                <div class="fw-bold small">${it.nombre}</div>
                <div class="text-muted" style="font-size:.68rem;">${it.codigo}</div>
            </td>
            <td><input type="number" class="form-control form-control-sm" value="${it.cantidad}" min="0.01" step="0.01"
                onchange="updateLine(${idx},'cantidad',this.value)" style="width:65px;"></td>
            <td><input type="number" class="form-control form-control-sm" value="${it.precio}" min="0" step="0.01"
                onchange="updateLine(${idx},'precio',this.value)" style="width:80px;"></td>
            <td class="fw-bold">$${sub.toFixed(2)}</td>
            <td><button class="btn btn-outline-danger btn-sm p-0 px-1" onclick="removeLine(${idx})"><i class="fas fa-trash-alt" style="font-size:.7rem;"></i></button></td>
        </tr>`;
    }).join('');
    document.getElementById('totalDisplay').innerText = '$' + total.toFixed(2);
}

// ── Buscar productos (debounce) ─────────────────────────────────────────────
let prodTimer = null;
function debounceProdSearch(q) {
    clearTimeout(prodTimer);
    if (!q.trim()) { document.getElementById('prodResults').style.display = 'none'; return; }
    prodTimer = setTimeout(() => buscarProductos(q), 280);
}
async function buscarProductos(q) {
    const res  = await fetch(`reservas.php?action=search_products&q=${encodeURIComponent(q)}`);
    const list = await res.json();
    const div  = document.getElementById('prodResults');
    if (!list.length) { div.innerHTML = '<div class="item text-muted">Sin resultados</div>'; div.style.display='block'; return; }
    div.innerHTML = list.map(p => {
        const stockBadge = p.es_servicio ? '<span class="badge bg-info text-dark ms-1" style="font-size:.6rem;">SERV</span>'
            : (parseFloat(p.stock) > 0 ? `<span class="badge bg-success ms-1" style="font-size:.6rem;">Stock: ${parseFloat(p.stock).toFixed(0)}</span>`
            : (p.es_reservable ? '<span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">📅 Res.</span>'
            : '<span class="badge bg-danger ms-1" style="font-size:.6rem;">Sin stock</span>'));
        return `<div class="item" onclick='seleccionarProducto(${JSON.stringify(p)})'>
            <strong>${p.nombre}</strong> ${stockBadge}
            <span class="float-end fw-bold text-primary">$${parseFloat(p.precio).toFixed(2)}</span>
            <div class="text-muted" style="font-size:.72rem;">${p.codigo} · ${p.categoria}</div>
        </div>`;
    }).join('');
    div.style.display = 'block';
}
function seleccionarProducto(prod) {
    addLine(prod);
}
document.addEventListener('click', e => {
    if (!e.target.closest('#prodSearch') && !e.target.closest('#prodResults'))
        document.getElementById('prodResults').style.display = 'none';
});

// ── Buscar clientes (debounce) ──────────────────────────────────────────────
let clientTimer = null;
function debounceClientSearch(q) {
    clearTimeout(clientTimer);
    if (!q.trim()) { document.getElementById('clientResults').style.display = 'none'; return; }
    clientTimer = setTimeout(() => buscarClientes(q), 280);
}
async function buscarClientes(q) {
    const res  = await fetch(`reservas.php?action=search_clients&q=${encodeURIComponent(q)}`);
    const list = await res.json();
    const div  = document.getElementById('clientResults');
    if (!list.length) { div.innerHTML = '<div class="item text-muted">Sin resultados — usa "+ Nuevo" para crear</div>'; div.style.display='block'; return; }
    div.innerHTML = list.map(c =>
        `<div class="item" onclick="seleccionarCliente(${c.id},'${c.nombre.replace(/'/g,"\\'")}','${(c.telefono||'').replace(/'/g,"\\'")}','${(c.direccion||'').replace(/'/g,"\\'")}')">
            <strong>${c.nombre}</strong>
            ${c.telefono ? `<span class="text-muted ms-2 small">${c.telefono}</span>` : ''}
            ${c.direccion ? `<div class="text-muted" style="font-size:.72rem;">${c.direccion.substring(0,50)}</div>` : ''}
        </div>`
    ).join('');
    div.style.display = 'block';
}
function seleccionarCliente(id, nombre, tel, dir) {
    document.getElementById('fIdCliente').value     = id;
    document.getElementById('fClienteNombre').value = nombre;
    document.getElementById('fClienteTel').value    = tel;
    document.getElementById('fClienteDir').value    = dir;
    document.getElementById('clientSearch').value   = nombre;
    document.getElementById('clientResults').style.display = 'none';
}
document.addEventListener('click', e => {
    if (!e.target.closest('#clientSearch') && !e.target.closest('#clientResults'))
        document.getElementById('clientResults').style.display = 'none';
});

// ── Nuevo cliente rápido ────────────────────────────────────────────────────
function abrirNuevoCliente() { newClientModal.show(); }
async function guardarNuevoCliente() {
    const nombre = document.getElementById('ncNombre').value.trim();
    if (!nombre) { showToast('Nombre requerido', 'warning'); return; }
    const res  = await fetch('reservas.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'create_client', nombre,
            telefono:  document.getElementById('ncTel').value.trim(),
            direccion: document.getElementById('ncDir').value.trim(),
            categoria: document.getElementById('ncCat').value })});
    const data = await res.json();
    if (data.status === 'success') {
        seleccionarCliente(data.id, data.nombre, data.telefono, data.direccion);
        newClientModal.hide();
        document.getElementById('ncNombre').value = '';
        document.getElementById('ncTel').value    = '';
        document.getElementById('ncDir').value    = '';
        showToast(`✓ Cliente "${data.nombre}" creado y seleccionado.`);
    } else {
        showToast('Error: ' + data.msg, 'danger');
    }
}

// ── Ver ticket ──────────────────────────────────────────────────────────────
function verTicket(id, deuda) {
    tCurrentId = id;
    document.getElementById('tModalId').innerText    = id;
    document.getElementById('ticketFrame').src       = `ticket_view.php?id=${id}`;
    const badge = document.getElementById('tDeudaBadge');
    badge.innerText  = deuda > 0 ? `Deuda: $${parseFloat(deuda).toFixed(2)}` : '✓ Saldado';
    badge.className  = `badge p-2 fs-6 ${deuda > 0 ? 'bg-danger' : 'bg-success'}`;
    // Botones de acción en el modal ticket
    document.getElementById('tActions').innerHTML = `
        <button class="btn btn-outline-danger flex-fill" onclick="procesarAccion(${id},'cancel')"><i class="fas fa-times me-1"></i>Cancelar</button>
        <button class="btn btn-success flex-fill fw-bold" onclick="procesarAccion(${id},'complete')"><i class="fas fa-check-circle me-1"></i>Entregar</button>`;
    ticketModal.show();
}

// ── Acciones (entregar, cancelar, cocina, confirmar pago) ───────────────────
async function procesarAccion(id, action) {
    const labels = {complete:'¿Marcar como ENTREGADA esta reserva? Se registrará en Kardex.', cancel:'¿CANCELAR esta reserva?'};
    if (!confirm(labels[action] || '¿Confirmar acción?')) return;
    const res  = await fetch('reservas.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id, action})});
    const data = await res.json();
    if (data.status === 'success') {
        showToast(action==='complete' ? '✓ Reserva entregada. Kardex actualizado.' : '✓ Reserva cancelada.');
        ticketModal.hide();
        reloadTable();
    } else if (data.status === 'warning') {
        alert('⚠️ Entregado con advertencias de stock:\n\n' + (data.alertas||[]).join('\n'));
        reloadTable();
    } else {
        showToast('Error: ' + data.msg, 'danger');
    }
}
async function confirmarPago(id) {
    if (!confirm(`¿Confirmar el pago de transferencia del pedido #${id}?`)) return;
    const res  = await fetch('pagos_api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id, action:'confirm_payment'})});
    const data = await res.json();
    if (data.status === 'success') { showToast('✓ Pago confirmado. Cliente notificado.'); reloadTable(); }
    else showToast('Error: ' + (data.msg || data.error), 'danger');
}
async function rechazarPago(id) {
    const motivo = prompt(`Motivo del rechazo para el pedido #${id} (opcional):`);
    if (motivo === null) return; // canceló el prompt
    if (!confirm(`¿RECHAZAR la transferencia del pedido #${id}? El pedido quedará cancelado.`)) return;
    const res  = await fetch('pagos_api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id, action:'reject_payment', motivo: motivo || 'Transferencia no verificada.'})});
    const data = await res.json();
    if (data.status === 'success') { showToast('Transferencia rechazada. Pedido cancelado.', 'warning'); reloadTable(); }
    else showToast('Error: ' + data.msg, 'danger');
}
async function enviarACocina(id) {
    if (!confirm('¿Enviar los productos elaborados de esta reserva a cocina?')) return;
    const res  = await fetch('reservas.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id, action:'send_to_kitchen'})});
    const data = await res.json();
    if (data.status === 'success') { showToast('✓ Pedido enviado a cocina.'); reloadTable(); }
    else showToast('Error: ' + data.msg, 'danger');
}

// ── Importar .ICS ───────────────────────────────────────────────────────────
function importICS() {
    const fi = document.createElement('input');
    fi.type = 'file'; fi.accept = '.ics';
    fi.onchange = async e => {
        const file = e.target.files[0];
        if (!file) return;
        const content = await file.text();
        const res  = await fetch('reservas.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'import_ics', content})});
        const data = await res.json();
        if (data.status === 'success') { showToast(`✓ ${data.count} reservas importadas.`); reloadTable(); }
        else showToast('Error: ' + data.msg, 'danger');
    };
    fi.click();
}

// ══════════════════════════════════════════════════════════════════════════════
// ALMANAQUE — Vista de calendario mensual
// ══════════════════════════════════════════════════════════════════════════════
let calYear  = new Date().getFullYear();
let calMonth = new Date().getMonth() + 1;
let calData  = [];

const CAL_MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                    'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

function setVista(vista) {
    const isCal = (vista === 'almanaque');
    document.getElementById('calView').style.display      = isCal ? 'block' : 'none';
    document.getElementById('tableSection').style.display = isCal ? 'none'  : 'block';
    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(isCal ? 'btnVistaCal' : 'btnVistaTbl').classList.add('active');
    if (isCal) loadCalendar(calYear, calMonth);
}

async function loadCalendar(year, month) {
    calYear = year; calMonth = month;
    document.getElementById('calTitle').textContent = 'Cargando...';
    try {
        const res = await fetch(`reservas.php?action=month_data&year=${year}&month=${month}`);
        calData = await res.json();
        renderCalendar();
    } catch(e) {
        document.getElementById('calGrid').innerHTML = '<div class="text-center text-danger py-5">Error al cargar datos del calendario.</div>';
    }
}

function prevMonth() {
    calMonth--;
    if (calMonth < 1) { calMonth = 12; calYear--; }
    loadCalendar(calYear, calMonth);
}
function nextMonth() {
    calMonth++;
    if (calMonth > 12) { calMonth = 1; calYear++; }
    loadCalendar(calYear, calMonth);
}
function goToday() {
    loadCalendar(new Date().getFullYear(), new Date().getMonth() + 1);
}

function getResClass(estadoReserva, dateStr) {
    const er = (estadoReserva || 'PENDIENTE').toUpperCase();
    if (er === 'ENTREGADO') return 'cal-res-entregado';
    if (er === 'CANCELADO') return 'cal-res-cancelado';
    const todayStr = new Date().toISOString().split('T')[0];
    if (dateStr < todayStr)  return 'cal-res-vencida';
    if (dateStr === todayStr) return 'cal-res-hoy';
    return 'cal-res-pendiente';
}

function renderCalendar() {
    document.getElementById('calTitle').textContent = CAL_MONTHS[calMonth - 1] + ' ' + calYear;

    const firstDow    = new Date(calYear, calMonth - 1, 1).getDay();    // 0=Dom
    const daysInMonth = new Date(calYear, calMonth, 0).getDate();
    const prevMDays   = new Date(calYear, calMonth - 1, 0).getDate();
    const startOffset = (firstDow === 0) ? 6 : firstDow - 1;           // Lun=0
    const todayStr    = new Date().toISOString().split('T')[0];

    // Agrupar reservas por fecha YYYY-MM-DD
    const byDate = {};
    calData.forEach(r => {
        if (!r.fecha_reserva) return;
        const d = r.fecha_reserva.split(' ')[0];
        (byDate[d] = byDate[d] || []).push(r);
    });

    let html = '';

    // Celdas del mes anterior (relleno)
    for (let i = startOffset - 1; i >= 0; i--) {
        html += `<div class="cal-day other-month"><span class="cal-day-num">${prevMDays - i}</span></div>`;
    }

    // Días del mes actual
    for (let day = 1; day <= daysInMonth; day++) {
        const dd      = String(day).padStart(2, '0');
        const mm      = String(calMonth).padStart(2, '0');
        const dateStr = `${calYear}-${mm}-${dd}`;
        const isToday = (dateStr === todayStr);
        const dayRes  = byDate[dateStr] || [];

        const numEl = `<span class="cal-day-num">${day}</span>`;

        let chips = dayRes.slice(0, 3).map(r => {
            const cls   = getResClass(r.estado_reserva, dateStr);
            const fname = (r.cliente_nombre || '?').split(' ')[0];
            const deuda = parseFloat(r.deuda || 0);
            const title = `${r.cliente_nombre} — $${parseFloat(r.total||0).toFixed(2)} — ${r.estado_reserva||'PENDIENTE'}`;
            return `<div class="cal-reservation ${cls}" onclick="verTicket(${r.id},${deuda})" title="${title.replace(/"/g,'&quot;')}">${fname}</div>`;
        }).join('');

        if (dayRes.length > 3) {
            chips += `<div class="cal-reservation cal-more" onclick="mostrarDiaDlg('${dateStr}')">+${dayRes.length - 3} más</div>`;
        }

        html += `<div class="cal-day ${isToday ? 'cal-today' : ''} ${dayRes.length ? 'has-reservas' : ''}">${numEl}${chips}</div>`;
    }

    // Celdas del mes siguiente (relleno para completar la grilla)
    const total     = startOffset + daysInMonth;
    const remaining = (7 - (total % 7)) % 7;
    for (let i = 1; i <= remaining; i++) {
        html += `<div class="cal-day other-month"><span class="cal-day-num">${i}</span></div>`;
    }

    document.getElementById('calGrid').innerHTML = html;
}

function mostrarDiaDlg(dateStr) {
    const dayRes = calData.filter(r => r.fecha_reserva && r.fecha_reserva.startsWith(dateStr));
    if (!dayRes.length) return;
    if (dayRes.length === 1) { verTicket(dayRes[0].id, parseFloat(dayRes[0].deuda || 0)); return; }

    const lista = dayRes.map(r =>
        `#${r.id} — ${r.cliente_nombre} — $${parseFloat(r.total||0).toFixed(2)} (${r.estado_reserva||'PENDIENTE'})`
    ).join('\n');
    const choice = prompt(
        `${dayRes.length} reservas el ${dateStr}:\n\n${lista}\n\nEscribe el ID para ver detalles (o Cancelar):`,
        ''
    );
    if (choice) {
        const r = dayRes.find(x => String(x.id) === choice.trim());
        if (r) verTicket(r.id, parseFloat(r.deuda || 0));
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// GESTIONAR ESTADO
// ══════════════════════════════════════════════════════════════════════════════
function openGestionarEstado(id, estadoActual, notaActual) {
    document.getElementById('gId').value    = id;
    document.getElementById('gModalId').innerText = '#' + id;
    document.getElementById('gEstado').value = estadoActual || 'PENDIENTE';
    document.getElementById('gNota').value   = notaActual || '';
    gestionarEstadoModal.show();
}

async function saveEstado() {
    const id     = document.getElementById('gId').value;
    const estado = document.getElementById('gEstado').value;
    const nota   = document.getElementById('gNota').value;
    const btn    = event.currentTarget;
    const old    = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Guardando...';
    try {
        const res  = await fetch('reservas.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'update_estado', id: parseInt(id), estado, nota})
        });
        const data = await res.json();
        if (data.status === 'success') {
            gestionarEstadoModal.hide();
            showToast('✓ Estado actualizado correctamente.');
            reloadTable();
        } else {
            showToast('Error: ' + (data.msg || 'Desconocido'), 'danger');
            btn.disabled = false; btn.innerHTML = old;
        }
    } catch(e) {
        showToast('Error de conexión', 'danger');
        btn.disabled = false; btn.innerHTML = old;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// CHECK CLIENTE POR TELÉFONO
// ══════════════════════════════════════════════════════════════════════════════
let checkClienteTimer = null;
function debounceCheckCliente(tel) {
    clearTimeout(checkClienteTimer);
    document.getElementById('clienteCrmBadge').innerHTML = '';
    if (tel.replace(/\D/g,'').length < 5) return;
    checkClienteTimer = setTimeout(() => checkClienteByTel(tel), 500);
}

async function checkClienteByTel(tel) {
    if (!tel || tel.replace(/\D/g,'').length < 5) return;
    const badge = document.getElementById('clienteCrmBadge');
    badge.innerHTML = '<span class="text-muted" style="font-size:.68rem;">Buscando…</span>';
    try {
        const res  = await fetch(`reservas.php?action=check_client_phone&tel=${encodeURIComponent(tel)}`);
        const data = await res.json();
        if (data.exists) {
            const catMap = {VIP:'⭐', Corporativo:'🏢', Regular:'', Empleado:'👤', Moroso:'⚠️'};
            const cat = data.cliente.categoria || 'Regular';
            badge.innerHTML = `<span class="crm-badge-ok">
                <i class="fas fa-user-check"></i> Cliente registrado: <strong>${data.cliente.nombre}</strong>
                ${catMap[cat]||''} ${cat !== 'Regular' ? cat : ''}
            </span>`;
        } else {
            badge.innerHTML = `<span class="crm-badge-no">
                <i class="fas fa-user-times"></i> Sin registro en CRM
                <button class="btn-guardar-cliente" onclick="abrirGuardarCliente()">→ Guardar como Cliente</button>
            </span>`;
        }
    } catch(e) {
        badge.innerHTML = '';
    }
}

function abrirGuardarCliente() {
    // Pre-rellenar modal de nuevo cliente con los datos del formulario de reserva
    document.getElementById('ncNombre').value = document.getElementById('fClienteNombre').value.trim();
    document.getElementById('ncTel').value    = document.getElementById('fClienteTel').value.trim();
    document.getElementById('ncDir').value    = document.getElementById('fClienteDir').value.trim();
    document.getElementById('ncCat').value    = 'Regular';
    newClientModal.show();
}

// Sobrescribir el guardar cliente para actualizar badge y vincular a la reserva
const _origGuardarNuevoCliente = guardarNuevoCliente;
guardarNuevoCliente = async function() {
    const nombre = document.getElementById('ncNombre').value.trim();
    if (!nombre) { showToast('Nombre requerido', 'warning'); return; }
    const res  = await fetch('reservas.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'create_client', nombre,
            telefono:  document.getElementById('ncTel').value.trim(),
            direccion: document.getElementById('ncDir').value.trim(),
            categoria: document.getElementById('ncCat').value })});
    const data = await res.json();
    if (data.status === 'success') {
        seleccionarCliente(data.id, data.nombre, data.telefono, data.direccion);
        newClientModal.hide();
        document.getElementById('ncNombre').value = '';
        document.getElementById('ncTel').value    = '';
        document.getElementById('ncDir').value    = '';
        showToast(`✓ Cliente "${data.nombre}" creado y vinculado.`);
        // Actualizar badge a "registrado"
        document.getElementById('clienteCrmBadge').innerHTML =
            `<span class="crm-badge-ok"><i class="fas fa-user-check"></i> Cliente registrado: <strong>${data.nombre}</strong></span>`;
    } else {
        showToast('Error: ' + data.msg, 'danger');
    }
};

// ══════════════════════════════════════════════════════════════════════════════
// REPORTE A4 — Productos reservados sin stock / por semana
// ══════════════════════════════════════════════════════════════════════════════
async function printReport() {
    const now   = new Date();
    const year  = now.getFullYear();
    const month = now.getMonth() + 1;
    showToast('Generando reporte…', 'info');
    try {
        const res  = await fetch(`reservas.php?action=report_data&year=${year}&month=${month}`);
        const data = await res.json();
        if (data.status !== 'success') { showToast('Error al generar reporte', 'danger'); return; }

        const fmtNum = n => parseFloat(n || 0).toFixed(2);
        const rowStyle = deficit => deficit > 0 ? 'background:#fff1f0;font-weight:700;' : '';

        const buildTable = (rows, showWeek) => {
            if (!rows.length) return '<p style="color:#888;font-style:italic;">Sin productos en esta categoría.</p>';
            return `<table style="width:100%;border-collapse:collapse;font-size:11px;margin-bottom:6px;">
                <thead><tr style="background:#1e293b;color:white;">
                    <th style="padding:6px 8px;text-align:left;">Producto</th>
                    <th style="padding:6px 8px;text-align:left;">Código</th>
                    <th style="padding:6px 8px;text-align:right;">Reservado</th>
                    <th style="padding:6px 8px;text-align:right;">Stock</th>
                    <th style="padding:6px 8px;text-align:right;background:#dc2626;">Déficit</th>
                    ${showWeek ? '' : '<th style="padding:6px 8px;text-align:center;"># Reservas</th>'}
                </tr></thead>
                <tbody>${rows.map(r => `
                    <tr style="border-bottom:1px solid #e2e8f0;${rowStyle(r.deficit)}">
                        <td style="padding:5px 8px;">${r.nombre}</td>
                        <td style="padding:5px 8px;color:#64748b;font-size:10px;">${r.codigo}</td>
                        <td style="padding:5px 8px;text-align:right;">${fmtNum(r.total_reservado)}</td>
                        <td style="padding:5px 8px;text-align:right;color:${parseFloat(r.stock_actual)>0?'#166534':'#dc2626'};">${fmtNum(r.stock_actual)}</td>
                        <td style="padding:5px 8px;text-align:right;color:#dc2626;">${parseFloat(r.deficit||0)>0?fmtNum(r.deficit):'—'}</td>
                        ${showWeek ? '' : `<td style="padding:5px 8px;text-align:center;">${r.num_reservas||0}</td>`}
                    </tr>`).join('')}
                </tbody></table>`;
        };

        const weekRanges = {1:'1 – 7', 2:'8 – 14', 3:'15 – 21', 4:'22 – fin de mes'};
        let weekSections = '';
        for (let w = 1; w <= 4; w++) {
            const wRows = data.by_week[w] || [];
            const sinSt = wRows.filter(r => parseFloat(r.deficit||0) > 0);
            weekSections += `
            <div style="margin-bottom:18px;">
                <h3 style="font-size:13px;font-weight:700;color:#1e293b;margin:0 0 6px;
                    border-bottom:2px solid #6366f1;padding-bottom:4px;">
                    Semana ${w} — Días ${weekRanges[w]}
                    ${sinSt.length ? `<span style="font-size:10px;color:#dc2626;font-weight:700;margin-left:8px;">⚠ ${sinSt.length} producto(s) con déficit</span>` : ''}
                </h3>
                ${buildTable(wRows, true)}
            </div>`;
        }

        const printDate = now.toLocaleDateString('es-CU', {day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'});
        const html = `<!DOCTYPE html><html lang="es"><head>
            <meta charset="UTF-8">
            <title>Reporte Stock Reservas — ${data.month_name} ${data.year}</title>
            <style>
                @page { size: A4; margin: 1.8cm 1.5cm; }
                body { font-family:'Segoe UI',Arial,sans-serif; font-size:12px; color:#1e293b; margin:0; }
                h1 { font-size:18px; font-weight:900; color:#0f172a; margin:0 0 2px; }
                h2 { font-size:14px; font-weight:800; color:#1e293b; margin:18px 0 8px;
                     border-left:4px solid #6366f1; padding-left:10px; }
                .header { border-bottom:3px solid #0f172a; padding-bottom:10px; margin-bottom:14px; }
                .sub { font-size:10px; color:#64748b; }
                .kpi-row { display:flex; gap:12px; margin-bottom:14px; }
                .kpi { flex:1; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; }
                .kpi .num { font-size:20px; font-weight:900; color:#6366f1; }
                .kpi .lbl { font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
                .alert-box { background:#fff1f0; border:1px solid #fca5a5; border-radius:6px;
                    padding:8px 12px; font-size:11px; color:#991b1b; margin-bottom:12px; }
                .footer { margin-top:20px; border-top:1px solid #e2e8f0; padding-top:6px;
                    font-size:9px; color:#94a3b8; text-align:center; }
                @media print { body { print-color-adjust:exact; -webkit-print-color-adjust:exact; } }
            </style></head><body>
            <div class="header">
                <h1>📦 Reporte de Reservas — Productos sin Stock</h1>
                <div class="sub">Generado: ${printDate} · Sistema PALWEB POS v3.0</div>
            </div>
            <div class="kpi-row">
                <div class="kpi"><div class="num">${data.all_products.length}</div><div class="lbl">Productos reservados</div></div>
                <div class="kpi"><div class="num" style="color:#dc2626">${data.sin_stock.length}</div><div class="lbl">Con déficit de stock</div></div>
            </div>
            ${data.sin_stock.length ? `<div class="alert-box">⚠️ Existen <strong>${data.sin_stock.length}</strong> producto(s) con stock insuficiente para cubrir todas las reservas pendientes.</div>` : ''}

            <h2>Sección 1 — Todos los productos reservados (pendientes)</h2>
            ${buildTable(data.all_products, false)}

            <h2>Sección 2 — A fabricar por semana del mes: ${data.month_name} ${data.year}</h2>
            ${weekSections}

            <div class="footer">Sistema PALWEB POS v3.0 · Reporte generado automáticamente</div>
            <script>window.onload=()=>{window.print();}<\/script>
        </body></html>`;

        const win = window.open('', '_blank', 'width=820,height=900');
        win.document.write(html);
        win.document.close();
    } catch(e) {
        showToast('Error al generar reporte: ' + e.message, 'danger');
    }
}
</script>
</body>
</html>
