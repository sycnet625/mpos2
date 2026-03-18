<?php
// API para app Android Reservas Offline
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config_loader.php';

$sucursalID = intval($_GET['sucursal_id'] ?? ($config['id_sucursal'] ?? 1));
$idAlmacen  = intval($config['id_almacen'] ?? 1);
$empID      = intval($config['id_empresa'] ?? 1);

$OFFLINE_API_KEY = $config['offline_api_key'] ?? '';
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($OFFLINE_API_KEY !== '' && $providedKey !== $OFFLINE_API_KEY) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'API key invalida']);
    exit;
}

$pdo->exec("ALTER TABLE ventas_cabecera ADD COLUMN IF NOT EXISTS canal_origen VARCHAR(30) DEFAULT 'POS'");

function safe_str($s, $max = 255) {
    return mb_substr(trim((string)$s), 0, $max);
}

function has_column(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->execute([$table, $column]);
    return intval($stmt->fetchColumn()) > 0;
}

function parse_fecha($input) {
    $text = trim((string)$input);
    if ($text === '') return date('Y-m-d H:i:s');
    return date('Y-m-d H:i:s', strtotime($text));
}

function calcular_sin_existencia(PDO $pdo, array $items, int $idAlmacen): int {
    $sinExistencia = 0;
    foreach ($items as $it) {
        $sku = safe_str($it['codigo'] ?? '', 100);
        if ($sku === '') continue;
        $stmtEs = $pdo->prepare("SELECT COALESCE(es_servicio,0) FROM productos WHERE codigo=?");
        $stmtEs->execute([$sku]);
        $esServ = intval($stmtEs->fetchColumn());
        if (!$esServ) {
            $stmtSt = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
            $stmtSt->execute([$sku, $idAlmacen]);
            $stock = floatval($stmtSt->fetchColumn());
            $qty = floatval($it['cantidad'] ?? 0);
            if ($stock < $qty) $sinExistencia = 1;
        }
    }
    return $sinExistencia;
}

function save_items(PDO $pdo, int $idVenta, array $items): void {
    foreach ($items as $it) {
        $sku = safe_str($it['codigo'] ?? '', 100);
        if ($sku === '') continue;
        $qty = floatval($it['cantidad'] ?? 0);
        $price = floatval($it['precio'] ?? 0);
        $name = safe_str($it['nombre'] ?? 'Producto', 255);
        $cat = safe_str($it['categoria'] ?? 'General', 100);
        $pdo->prepare("INSERT INTO ventas_detalle
            (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto, codigo_producto, categoria_producto)
            VALUES (?,?,?,?,?,?,?)")
            ->execute([$idVenta, $sku, $qty, $price, $name, $sku, $cat]);
    }
}

function create_reservation(PDO $pdo, array $payload, int $sucursalID, int $idAlmacen, int $empID): array {
    $clienteNombre = safe_str($payload['cliente_nombre'] ?? 'Sin nombre', 100);
    $clienteTel = safe_str($payload['cliente_telefono'] ?? '', 50);
    $clienteDir = safe_str($payload['cliente_direccion'] ?? '', 255);
    $idCliente = intval($payload['id_cliente'] ?? 0);
    $fechaReserva = parse_fecha($payload['fecha_reserva'] ?? '');
    $notas = safe_str($payload['notas'] ?? '', 1000);
    $metodo = safe_str($payload['metodo_pago'] ?? 'Efectivo', 50);
    $abono = floatval($payload['abono'] ?? 0);
    $estadoPago = safe_str($payload['estado_pago'] ?? 'pendiente', 20);
    $items = $payload['items'] ?? [];
    $costoMensajeria = floatval($payload['costo_mensajeria'] ?? 0);
    $canal = safe_str($payload['canal_origen'] ?? 'POS', 30);
    $localUuid = safe_str($payload['local_uuid'] ?? '', 120);

    if (empty($items)) throw new Exception('La reserva no tiene items');

    if ($localUuid !== '') {
        $stmt = $pdo->prepare("SELECT id, sin_existencia FROM ventas_cabecera WHERE uuid_venta=? LIMIT 1");
        $stmt->execute([$localUuid]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            return ['remote_id' => intval($exists['id']), 'sin_existencia' => intval($exists['sin_existencia'] ?? 0)];
        }
    }

    $total = 0;
    foreach ($items as $it) {
        $total += floatval($it['cantidad'] ?? 0) * floatval($it['precio'] ?? 0);
    }
    $total += $costoMensajeria;

    $uuid = $localUuid !== '' ? $localUuid : uniqid('R-', true);

    $pdo->prepare("INSERT INTO ventas_cabecera
        (uuid_venta, fecha, total, metodo_pago, id_sucursal, id_almacen, tipo_servicio,
         fecha_reserva, notas, cliente_nombre, cliente_telefono, cliente_direccion,
         abono, estado_pago, id_empresa, estado_reserva, sincronizado, id_caja, id_cliente, canal_origen)
        VALUES (?,NOW(),?,?,?,?,'reserva',?,?,?,?,?,?,?,?,'PENDIENTE',0,1,?,?)")
        ->execute([
            $uuid, $total, $metodo, $sucursalID, $idAlmacen,
            $fechaReserva, $notas, $clienteNombre, $clienteTel, $clienteDir,
            $abono, $estadoPago, $empID, $idCliente, $canal
        ]);

    $idVenta = intval($pdo->lastInsertId());
    save_items($pdo, $idVenta, $items);

    $sinExistencia = calcular_sin_existencia($pdo, $items, $idAlmacen);
    $pdo->prepare("UPDATE ventas_cabecera SET sin_existencia=? WHERE id=?")->execute([$sinExistencia, $idVenta]);

    return ['remote_id' => $idVenta, 'sin_existencia' => $sinExistencia];
}

function get_reservation_server_updated_epoch(PDO $pdo, int $remoteId): int {
    $hasUpdatedAt = has_column($pdo, 'ventas_cabecera', 'updated_at');
    $field = $hasUpdatedAt ? 'updated_at' : 'fecha';
    $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(COALESCE({$field}, fecha)) FROM ventas_cabecera WHERE id=? LIMIT 1");
    $stmt->execute([$remoteId]);
    return intval($stmt->fetchColumn() ?: 0);
}

function update_reservation(PDO $pdo, array $payload, int $sucursalID, int $idAlmacen): array {
    $remoteId = intval($payload['remote_id'] ?? 0);
    if ($remoteId <= 0) throw new Exception('remote_id requerido para actualizar');

    $clientTs = intval($payload['client_updated_at_epoch'] ?? 0);
    if ($clientTs > 0) {
        $serverTs = get_reservation_server_updated_epoch($pdo, $remoteId);
        if ($serverTs > $clientTs) throw new Exception('CONFLICT server_updated_at=' . $serverTs);
    }

    $clienteNombre = safe_str($payload['cliente_nombre'] ?? 'Sin nombre', 100);
    $clienteTel = safe_str($payload['cliente_telefono'] ?? '', 50);
    $clienteDir = safe_str($payload['cliente_direccion'] ?? '', 255);
    $idCliente = intval($payload['id_cliente'] ?? 0);
    $fechaReserva = parse_fecha($payload['fecha_reserva'] ?? '');
    $notas = safe_str($payload['notas'] ?? '', 1000);
    $metodo = safe_str($payload['metodo_pago'] ?? 'Efectivo', 50);
    $abono = floatval($payload['abono'] ?? 0);
    $items = $payload['items'] ?? [];
    $costoMensajeria = floatval($payload['costo_mensajeria'] ?? 0);
    $canal = safe_str($payload['canal_origen'] ?? 'POS', 30);

    if (empty($items)) throw new Exception('La reserva no tiene items');

    $total = 0;
    foreach ($items as $it) {
        $total += floatval($it['cantidad'] ?? 0) * floatval($it['precio'] ?? 0);
    }
    $total += $costoMensajeria;

    $changedFields = $payload['changed_fields'] ?? null;
    $validFields = ['cliente_nombre','cliente_telefono','cliente_direccion','id_cliente','fecha_reserva','notas','metodo_pago','abono','canal_origen','items','costo_mensajeria'];
    $applyAll = !is_array($changedFields);
    if (!$applyAll) {
        $changedFields = array_values(array_filter($changedFields, fn($f) => in_array($f, $validFields, true)));
    }

    $set = [];
    $vals = [];
    $fieldMap = [
        'cliente_nombre' => $clienteNombre,
        'cliente_telefono' => $clienteTel,
        'cliente_direccion' => $clienteDir,
        'id_cliente' => $idCliente,
        'fecha_reserva' => $fechaReserva,
        'notas' => $notas,
        'metodo_pago' => $metodo,
        'abono' => $abono,
        'canal_origen' => $canal,
    ];
    foreach ($fieldMap as $k => $v) {
        if ($applyAll || in_array($k, $changedFields, true)) {
            $set[] = "{$k}=?";
            $vals[] = $v;
        }
    }
    if ($applyAll || in_array('costo_mensajeria', $changedFields, true) || in_array('items', $changedFields, true)) {
        $set[] = "total=?";
        $vals[] = $total;
    }
    if (!empty($set)) {
        $vals[] = $remoteId;
        $vals[] = $sucursalID;
        $pdo->prepare("UPDATE ventas_cabecera SET " . implode(',', $set) . " WHERE id=? AND id_sucursal=? AND tipo_servicio='reserva'")
            ->execute($vals);
    }

    $itemsChanged = $applyAll || in_array('items', $changedFields, true);
    if ($itemsChanged) {
        $pdo->prepare("DELETE FROM ventas_detalle WHERE id_venta_cabecera=?")->execute([$remoteId]);
        save_items($pdo, $remoteId, $items);
    }

    $sinExistencia = $itemsChanged ? calcular_sin_existencia($pdo, $items, $idAlmacen) : 0;
    $pdo->prepare("UPDATE ventas_cabecera SET sin_existencia=? WHERE id=?")->execute([$sinExistencia, $remoteId]);

    return ['remote_id' => $remoteId, 'sin_existencia' => $sinExistencia];
}

function fetch_products(PDO $pdo, int $sucursalID, int $idAlmacen, int $empID): array {
    $updatedAfter = intval($_GET['updated_after'] ?? 0);
    $hasUpdatedAt = has_column($pdo, 'productos', 'updated_at');
    $updatedFilter = ($updatedAfter > 0 && $hasUpdatedAt) ? "AND UNIX_TIMESTAMP(p.updated_at) > {$updatedAfter}" : '';
    $sucFilter = ($sucursalID >= 1 && $sucursalID <= 6) ? "AND p.es_suc{$sucursalID}=1" : '';

    $sqlProducts = "SELECT p.codigo, p.nombre, p.precio, p.categoria,
                           COALESCE(p.es_servicio,0) AS es_servicio,
                           COALESCE(p.es_reservable,0) AS es_reservable,
                           COALESCE(SUM(sa.cantidad),0) AS stock
                    FROM productos p
                    LEFT JOIN stock_almacen sa ON sa.id_producto=p.codigo AND sa.id_almacen=?
                    WHERE p.activo=1 AND p.id_empresa=? AND p.es_materia_prima=0 {$sucFilter} {$updatedFilter}
                    GROUP BY p.codigo, p.nombre, p.precio, p.categoria, p.es_servicio, p.es_reservable
                    HAVING stock > 0 OR COALESCE(p.es_reservable,0)=1 OR COALESCE(p.es_servicio,0)=1
                    ORDER BY p.nombre ASC";
    $stmtP = $pdo->prepare($sqlProducts);
    $stmtP->execute([$idAlmacen, $empID]);
    return $stmtP->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_clients(PDO $pdo): array {
    $updatedAfter = intval($_GET['updated_after'] ?? 0);
    $hasUpdatedAt = has_column($pdo, 'clientes', 'updated_at');
    $updatedFilter = ($updatedAfter > 0 && $hasUpdatedAt) ? "AND UNIX_TIMESTAMP(updated_at) > {$updatedAfter}" : '';

    $stmtC = $pdo->prepare("SELECT id, nombre, telefono, direccion, categoria
                            FROM clientes WHERE activo=1 {$updatedFilter}
                            ORDER BY id DESC LIMIT 2000");
    $stmtC->execute();
    return $stmtC->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_reservations(PDO $pdo, int $sucursalID, int $idAlmacen): array {
    $updatedAfter = intval($_GET['updated_after'] ?? 0);
    $hasUpdatedAt = has_column($pdo, 'ventas_cabecera', 'updated_at');
    $updatedField = $hasUpdatedAt ? 'updated_at' : 'fecha';
    $updatedFilter = ($updatedAfter > 0) ? "AND UNIX_TIMESTAMP({$updatedField}) > {$updatedAfter}" : '';

    $stmtR = $pdo->prepare("SELECT id, uuid_venta, cliente_nombre, cliente_telefono, cliente_direccion,
                                   id_cliente, fecha_reserva, UNIX_TIMESTAMP(fecha_reserva) AS fecha_reserva_epoch,
                                   UNIX_TIMESTAMP(COALESCE({$updatedField}, fecha)) AS server_updated_at_epoch,
                                   notas, metodo_pago, COALESCE(canal_origen,'POS') AS canal_origen,
                                   COALESCE(estado_pago,'pendiente') AS estado_pago,
                                   COALESCE(estado_reserva,'PENDIENTE') AS estado_reserva,
                                   COALESCE(abono,0) AS abono, COALESCE(total,0) AS total,
                                   COALESCE(sin_existencia,0) AS sin_existencia
                            FROM ventas_cabecera
                            WHERE id_sucursal=? AND tipo_servicio='reserva'
                              AND fecha_reserva >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                              AND fecha_reserva < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 2 MONTH)
                              {$updatedFilter}
                            ORDER BY fecha_reserva ASC
                            LIMIT 5000");
    $stmtR->execute([$sucursalID]);
    $reservations = $stmtR->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reservations as &$r) {
        $stmtD = $pdo->prepare("SELECT id_producto AS codigo, nombre_producto AS nombre,
                                       categoria_producto AS categoria, cantidad, precio,
                                       COALESCE((SELECT es_servicio FROM productos WHERE codigo=id_producto),0) AS es_servicio,
                                       COALESCE((SELECT SUM(cantidad) FROM stock_almacen WHERE id_producto=ventas_detalle.id_producto AND id_almacen=?),0) AS stock
                                FROM ventas_detalle
                                WHERE id_venta_cabecera=?");
        $stmtD->execute([$idAlmacen, intval($r['id'])]);
        $r['items'] = $stmtD->fetchAll(PDO::FETCH_ASSOC);
        $r['local_uuid'] = $r['uuid_venta'] ?: ('remote-' . $r['id']);
        $subtotal = 0.0;
        foreach ($r['items'] as $it) {
            $subtotal += floatval($it['cantidad']) * floatval($it['precio']);
        }
        $r['costo_mensajeria'] = max(0, floatval($r['total']) - $subtotal);
    }
    unset($r);
    return $reservations;
}

function fetch_reservation_detail(PDO $pdo, int $id, int $sucursalID, int $idAlmacen): ?array {
    $hasUpdatedAt = has_column($pdo, 'ventas_cabecera', 'updated_at');
    $updatedField = $hasUpdatedAt ? 'updated_at' : 'fecha';
    $stmt = $pdo->prepare("SELECT id, uuid_venta, cliente_nombre, cliente_telefono, cliente_direccion,
                                  id_cliente, fecha_reserva, UNIX_TIMESTAMP(fecha_reserva) AS fecha_reserva_epoch,
                                  UNIX_TIMESTAMP(COALESCE({$updatedField}, fecha)) AS server_updated_at_epoch,
                                  notas, metodo_pago, COALESCE(canal_origen,'POS') AS canal_origen,
                                  COALESCE(estado_pago,'pendiente') AS estado_pago,
                                  COALESCE(estado_reserva,'PENDIENTE') AS estado_reserva,
                                  COALESCE(abono,0) AS abono, COALESCE(total,0) AS total,
                                  COALESCE(sin_existencia,0) AS sin_existencia
                           FROM ventas_cabecera WHERE id=? AND id_sucursal=? AND tipo_servicio='reserva' LIMIT 1");
    $stmt->execute([$id, $sucursalID]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $stmtD = $pdo->prepare("SELECT id_producto AS codigo, nombre_producto AS nombre,
                                   categoria_producto AS categoria, cantidad, precio,
                                   COALESCE((SELECT es_servicio FROM productos WHERE codigo=id_producto),0) AS es_servicio,
                                   COALESCE((SELECT SUM(cantidad) FROM stock_almacen WHERE id_producto=ventas_detalle.id_producto AND id_almacen=?),0) AS stock
                            FROM ventas_detalle WHERE id_venta_cabecera=?");
    $stmtD->execute([$idAlmacen, intval($r['id'])]);
    $r['items'] = $stmtD->fetchAll(PDO::FETCH_ASSOC);
    $r['local_uuid'] = $r['uuid_venta'] ?: ('remote-' . $r['id']);
    return $r;
}

function fetch_platform_notifications(PDO $pdo, int $sinceId, int $limit = 20): array {
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_notifications (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        tipo       VARCHAR(30)  NOT NULL,
        event_key  VARCHAR(80)  NOT NULL DEFAULT '',
        titulo     VARCHAR(200) NOT NULL,
        cuerpo     TEXT,
        url        VARCHAR(500),
        leida      TINYINT(1)  DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tipo_leida (tipo, leida)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $limit = max(1, min(50, $limit));
    $stmt = $pdo->prepare(
        "SELECT id, tipo, event_key, titulo, cuerpo, url, created_at
           FROM push_notifications
          WHERE id > ? AND tipo IN ('operador', 'todos')
          ORDER BY id ASC
          LIMIT {$limit}"
    );
    $stmt->execute([$sinceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'bootstrap' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'status' => 'success',
            'server_time' => date('c'),
            'products' => fetch_products($pdo, $sucursalID, $idAlmacen, $empID),
            'clients' => fetch_clients($pdo),
            'reservations' => fetch_reservations($pdo, $sucursalID, $idAlmacen),
        ]);
        exit;
    }

    if ($action === 'download_products' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $products = fetch_products($pdo, $sucursalID, $idAlmacen, $empID);
        echo json_encode([
            'status' => 'success',
            'products' => $products,
            'count' => count($products),
            'server_time' => date('c'),
        ]);
        exit;
    }

    if ($action === 'download_clients' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $clients = fetch_clients($pdo);
        echo json_encode([
            'status' => 'success',
            'clients' => $clients,
            'count' => count($clients),
            'server_time' => date('c'),
        ]);
        exit;
    }

    if ($action === 'download_reservations' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $reservations = fetch_reservations($pdo, $sucursalID, $idAlmacen);
        echo json_encode([
            'status' => 'success',
            'reservations' => $reservations,
            'count' => count($reservations),
            'server_time' => date('c'),
        ]);
        exit;
    }

    if ($action === 'reservation_detail' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) throw new Exception('id requerido');
        $detail = fetch_reservation_detail($pdo, $id, $sucursalID, $idAlmacen);
        if (!$detail) throw new Exception('Reserva no encontrada');
        echo json_encode(['status' => 'success', 'reservation' => $detail]);
        exit;
    }

    if ($action === 'changes_since' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $since = intval($_GET['since'] ?? 0);
        $_GET['updated_after'] = $since;
        $resCount = count(fetch_reservations($pdo, $sucursalID, $idAlmacen));
        $prodCount = count(fetch_products($pdo, $sucursalID, $idAlmacen, $empID));
        $cliCount = count(fetch_clients($pdo));
        echo json_encode([
            'status' => 'success',
            'since' => $since,
            'reservations_changed' => $resCount,
            'products_changed' => $prodCount,
            'clients_changed' => $cliCount,
            'server_time' => date('c'),
        ]);
        exit;
    }

    if ($action === 'notifications_feed' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $sinceId = intval($_GET['since_id'] ?? 0);
        $rows = fetch_platform_notifications($pdo, $sinceId, intval($_GET['limit'] ?? 20));
        echo json_encode([
            'status' => 'success',
            'notifications' => $rows,
            'count' => count($rows),
            'server_time' => date('c'),
        ]);
        exit;
    }

    if ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $ops = $input['operations'] ?? [];
        if (!is_array($ops)) $ops = [];

        $results = [];
        foreach ($ops as $op) {
            $opId = safe_str($op['op_id'] ?? '');
            $type = safe_str($op['type'] ?? '');
            $payload = $op['payload'] ?? [];

            try {
                $pdo->beginTransaction();

                if ($type === 'create_reservation') {
                    $r = create_reservation($pdo, $payload, $sucursalID, $idAlmacen, $empID);
                    $pdo->commit();
                    $results[] = [
                        'ok' => true,
                        'op_id' => $opId,
                        'msg' => 'Reserva creada',
                        'remote_reservation_id' => $r['remote_id'],
                        'sin_existencia' => $r['sin_existencia'],
                    ];
                } elseif ($type === 'update_reservation') {
                    $r = update_reservation($pdo, $payload, $sucursalID, $idAlmacen);
                    $pdo->commit();
                    $results[] = [
                        'ok' => true,
                        'op_id' => $opId,
                        'msg' => 'Reserva actualizada',
                        'remote_reservation_id' => $r['remote_id'],
                        'sin_existencia' => $r['sin_existencia'],
                    ];
                } elseif ($type === 'complete_reservation') {
                    $remoteId = intval($payload['remote_id'] ?? 0);
                    if ($remoteId <= 0) throw new Exception('remote_id requerido');
                    $clientTs = intval($payload['client_updated_at_epoch'] ?? 0);
                    if ($clientTs > 0 && get_reservation_server_updated_epoch($pdo, $remoteId) > $clientTs) {
                        throw new Exception('CONFLICT server_updated_at=' . get_reservation_server_updated_epoch($pdo, $remoteId));
                    }
                    $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva='ENTREGADO' WHERE id=? AND id_sucursal=?")
                        ->execute([$remoteId, $sucursalID]);
                    $pdo->commit();
                    $results[] = ['ok' => true, 'op_id' => $opId, 'msg' => 'Reserva entregada'];
                } elseif ($type === 'cancel_reservation') {
                    $remoteId = intval($payload['remote_id'] ?? 0);
                    if ($remoteId <= 0) throw new Exception('remote_id requerido');
                    $clientTs = intval($payload['client_updated_at_epoch'] ?? 0);
                    if ($clientTs > 0 && get_reservation_server_updated_epoch($pdo, $remoteId) > $clientTs) {
                        throw new Exception('CONFLICT server_updated_at=' . get_reservation_server_updated_epoch($pdo, $remoteId));
                    }
                    $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva='CANCELADO' WHERE id=? AND id_sucursal=?")
                        ->execute([$remoteId, $sucursalID]);
                    $pdo->commit();
                    $results[] = ['ok' => true, 'op_id' => $opId, 'msg' => 'Reserva cancelada'];
                } elseif ($type === 'update_status') {
                    $remoteId = intval($payload['remote_id'] ?? 0);
                    if ($remoteId <= 0) throw new Exception('remote_id requerido');
                    $clientTs = intval($payload['client_updated_at_epoch'] ?? 0);
                    if ($clientTs > 0 && get_reservation_server_updated_epoch($pdo, $remoteId) > $clientTs) {
                        throw new Exception('CONFLICT server_updated_at=' . get_reservation_server_updated_epoch($pdo, $remoteId));
                    }
                    $estado = safe_str($payload['estado'] ?? 'PENDIENTE', 30);
                    $nota = safe_str($payload['nota'] ?? '', 1000);
                    $validos = ['PENDIENTE','EN_PREPARACION','EN_CAMINO','ENTREGADO','CANCELADO'];
                    if (!in_array($estado, $validos, true)) throw new Exception('Estado invalido');
                    $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva=?, notas=? WHERE id=? AND id_sucursal=? AND tipo_servicio='reserva'")
                        ->execute([$estado, $nota, $remoteId, $sucursalID]);
                    $pdo->commit();
                    $results[] = ['ok' => true, 'op_id' => $opId, 'msg' => 'Estado actualizado'];
                } elseif ($type === 'create_client') {
                    $nombre = safe_str($payload['nombre'] ?? '', 100);
                    if ($nombre === '') throw new Exception('Nombre requerido');
                    $telefono = safe_str($payload['telefono'] ?? '', 20);
                    $direccion = safe_str($payload['direccion'] ?? '', 500);
                    $categoria = safe_str($payload['categoria'] ?? 'Regular', 30);
                    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
                    $pdo->prepare("INSERT INTO clientes (nombre, telefono, direccion, categoria, uuid, origen)
                                   VALUES (?,?,?,?,?,'Android Offline')")
                        ->execute([$nombre, $telefono, $direccion, $categoria, $uuid]);
                    $id = intval($pdo->lastInsertId());
                    $pdo->commit();
                    $results[] = ['ok' => true, 'op_id' => $opId, 'msg' => 'Cliente creado', 'remote_client_id' => $id];
                } else {
                    throw new Exception('Operacion no soportada: ' . $type);
                }
            } catch (Throwable $inner) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $results[] = [
                    'ok' => false,
                    'op_id' => $opId,
                    'msg' => $inner->getMessage(),
                ];
            }
        }

        echo json_encode([
            'status' => 'success',
            'results' => $results,
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Accion no valida']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
