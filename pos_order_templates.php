<?php
require_once 'db.php';
require_once 'config_loader.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function pos_order_auth_ok(): bool
{
    return (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
        || !empty($_SESSION['cajero']);
}

function pos_order_actor(): string
{
    return trim((string)($_SESSION['admin_user'] ?? $_SESSION['admin_user_name'] ?? $_SESSION['cajero'] ?? 'POS'));
}

function pos_order_ctx(array $config): array
{
    return [
        'empresa'  => (int)($_SESSION['id_empresa'] ?? $config['id_empresa'] ?? 1),
        'sucursal' => (int)($_SESSION['id_sucursal'] ?? $config['id_sucursal'] ?? 1),
        'almacen'  => (int)($_SESSION['id_almacen'] ?? $config['id_almacen'] ?? 1),
    ];
}

function pos_order_templates_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pos_order_templates (
            id INT NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(120) NOT NULL,
            cliente_nombre VARCHAR(120) DEFAULT NULL,
            tipo_servicio VARCHAR(50) DEFAULT 'mostrador',
            mensajero_nombre VARCHAR(100) DEFAULT NULL,
            delivery_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            source_sale_id INT DEFAULT NULL,
            items_json LONGTEXT NOT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            id_empresa INT NOT NULL DEFAULT 1,
            id_sucursal INT NOT NULL DEFAULT 1,
            id_almacen INT NOT NULL DEFAULT 1,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_tpl_scope (id_empresa, id_sucursal, activo),
            KEY idx_tpl_cliente (cliente_nombre),
            KEY idx_tpl_sale (source_sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    try {
        $hasDelivery = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'pos_order_templates'
               AND COLUMN_NAME = 'delivery_cost'"
        )->fetchColumn();
        if (!(int)$hasDelivery) {
            $pdo->exec("ALTER TABLE pos_order_templates ADD COLUMN delivery_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER mensajero_nombre");
        }
    } catch (Throwable $e) {
        // Si information_schema no está disponible, seguimos con la tabla base.
    }
}

function pos_sale_order_payload(PDO $pdo, int $saleId, array $ctx): array
{
    $stmt = $pdo->prepare(
        "SELECT id, cliente_nombre, cliente_telefono, cliente_direccion, total,
                tipo_servicio, mensajero_nombre, metodo_pago
         FROM ventas_cabecera
         WHERE id = ? AND id_empresa = ? AND id_sucursal = ?
         LIMIT 1"
    );
    $stmt->execute([$saleId, $ctx['empresa'], $ctx['sucursal']]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        throw new RuntimeException('Venta no encontrada');
    }

    if (floatval($sale['total']) <= 0 || stripos((string)($sale['metodo_pago'] ?? ''), 'ANULADO') !== false) {
        throw new RuntimeException('La venta no es válida para reutilizar');
    }

    $stmtDet = $pdo->prepare(
        "SELECT id_producto, codigo_producto, nombre_producto, cantidad, precio
         FROM ventas_detalle
         WHERE id_venta_cabecera = ? AND cantidad > 0
         ORDER BY id ASC"
    );
    $stmtDet->execute([$saleId]);
    $items = [];
    foreach ($stmtDet->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $codigo = trim((string)($row['codigo_producto'] ?: $row['id_producto']));
        if ($codigo === '') {
            continue;
        }
        $items[] = [
            'id'    => $codigo,
            'codigo'=> $codigo,
            'name'  => (string)($row['nombre_producto'] ?? $codigo),
            'qty'   => (float)$row['cantidad'],
            'price' => (float)$row['precio'],
            'note'  => '',
        ];
    }

    if (empty($items)) {
        throw new RuntimeException('La venta no tiene productos reutilizables');
    }

    return [
        'source'             => 'sale',
        'source_id'          => $saleId,
        'source_label'       => 'Venta #' . $saleId,
        'cliente_nombre'     => (string)($sale['cliente_nombre'] ?? ''),
        'cliente_telefono'   => (string)($sale['cliente_telefono'] ?? ''),
        'cliente_direccion'  => (string)($sale['cliente_direccion'] ?? ''),
        'tipo_servicio'      => (string)($sale['tipo_servicio'] ?? 'mostrador'),
        'mensajero_nombre'   => (string)($sale['mensajero_nombre'] ?? ''),
        'delivery_cost'      => max(0, round((float)$sale['total'] - array_reduce($items, static function ($sum, $item) {
            return $sum + ((float)$item['qty'] * (float)$item['price']);
        }, 0), 2)),
        'items'              => $items,
    ];
}

function pos_last_client_order_payload(PDO $pdo, string $clientName, array $ctx): array
{
    $clientName = trim($clientName);
    if ($clientName === '') {
        throw new RuntimeException('Cliente inválido');
    }

    $stmt = $pdo->prepare(
        "SELECT id
         FROM ventas_cabecera
         WHERE id_empresa = ? AND id_sucursal = ?
           AND cliente_nombre = ?
           AND total > 0
           AND (metodo_pago IS NULL OR metodo_pago NOT LIKE '%ANULADO%')
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([$ctx['empresa'], $ctx['sucursal'], $clientName]);
    $saleId = (int)$stmt->fetchColumn();
    if ($saleId <= 0) {
        throw new RuntimeException('Ese cliente no tiene pedidos previos');
    }

    $payload = pos_sale_order_payload($pdo, $saleId, $ctx);
    $payload['source'] = 'last_client';
    $payload['source_label'] = 'Último pedido de ' . $clientName;
    return $payload;
}

function pos_template_payload(PDO $pdo, int $templateId, array $ctx): array
{
    pos_order_templates_ensure_table($pdo);

    $stmt = $pdo->prepare(
            "SELECT *
         FROM pos_order_templates
         WHERE id = ? AND activo = 1 AND id_empresa = ? AND id_sucursal = ?
         LIMIT 1"
    );
    $stmt->execute([$templateId, $ctx['empresa'], $ctx['sucursal']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Plantilla no encontrada');
    }

    $items = json_decode((string)$row['items_json'], true);
    if (!is_array($items) || empty($items)) {
        throw new RuntimeException('La plantilla no tiene productos');
    }

    return [
        'source'            => 'template',
        'source_id'         => (int)$row['id'],
        'source_label'      => (string)$row['nombre'],
        'cliente_nombre'    => (string)($row['cliente_nombre'] ?? ''),
        'cliente_telefono'  => '',
        'cliente_direccion' => '',
        'tipo_servicio'     => (string)($row['tipo_servicio'] ?? 'mostrador'),
        'mensajero_nombre'  => (string)($row['mensajero_nombre'] ?? ''),
        'delivery_cost'     => (float)($row['delivery_cost'] ?? 0),
        'items'             => array_values(array_map(static function ($item) {
            return [
                'id'     => (string)($item['id'] ?? $item['codigo'] ?? ''),
                'codigo' => (string)($item['codigo'] ?? $item['id'] ?? ''),
                'name'   => (string)($item['name'] ?? ''),
                'qty'    => (float)($item['qty'] ?? 0),
                'price'  => (float)($item['price'] ?? 0),
                'note'   => (string)($item['note'] ?? ''),
            ];
        }, $items)),
    ];
}

function pos_recent_clients(PDO $pdo, array $ctx, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));

    $stmt = $pdo->prepare(
        "SELECT vc.cliente_nombre,
                MAX(vc.id) AS last_sale_id,
                MAX(vc.fecha) AS last_sale_date,
                COALESCE(MAX(NULLIF(vc.cliente_telefono, '')), '') AS cliente_telefono,
                COALESCE(MAX(NULLIF(vc.cliente_direccion, '')), '') AS cliente_direccion
         FROM ventas_cabecera vc
         WHERE vc.id_empresa = ?
           AND vc.id_sucursal = ?
           AND COALESCE(TRIM(vc.cliente_nombre), '') <> ''
           AND vc.total > 0
           AND (vc.metodo_pago IS NULL OR vc.metodo_pago NOT LIKE '%ANULADO%')
         GROUP BY vc.cliente_nombre
         ORDER BY last_sale_id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$ctx['empresa'], $ctx['sucursal']]);

    return array_map(static function (array $row): array {
        return [
            'nombre' => (string)($row['cliente_nombre'] ?? ''),
            'telefono' => (string)($row['cliente_telefono'] ?? ''),
            'direccion' => (string)($row['cliente_direccion'] ?? ''),
            'last_sale_id' => (int)($row['last_sale_id'] ?? 0),
            'last_sale_date' => (string)($row['last_sale_date'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

if (!pos_order_auth_ok()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'No autorizado']);
    exit;
}

$ctx = pos_order_ctx($config ?? []);
$action = trim((string)($_GET['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET' && $action === 'sale_payload') {
        echo json_encode(['status' => 'success', 'order' => pos_sale_order_payload($pdo, (int)($_GET['id'] ?? 0), $ctx)]);
        exit;
    }

    if ($method === 'GET' && $action === 'last_client') {
        echo json_encode(['status' => 'success', 'order' => pos_last_client_order_payload($pdo, (string)($_GET['client_name'] ?? ''), $ctx)]);
        exit;
    }

    if ($method === 'GET' && $action === 'template_payload') {
        echo json_encode(['status' => 'success', 'order' => pos_template_payload($pdo, (int)($_GET['id'] ?? 0), $ctx)]);
        exit;
    }

    if ($method === 'GET' && $action === 'list_templates') {
        pos_order_templates_ensure_table($pdo);
        $stmt = $pdo->prepare(
            "SELECT id, nombre, cliente_nombre, tipo_servicio, mensajero_nombre, source_sale_id, items_json, created_at
             FROM pos_order_templates
             WHERE activo = 1 AND id_empresa = ? AND id_sucursal = ?
             ORDER BY updated_at DESC, id DESC"
        );
        $stmt->execute([$ctx['empresa'], $ctx['sucursal']]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items = json_decode((string)$row['items_json'], true);
            $rows[] = [
                'id'              => (int)$row['id'],
                'nombre'          => (string)$row['nombre'],
                'cliente_nombre'  => (string)($row['cliente_nombre'] ?? ''),
                'tipo_servicio'   => (string)($row['tipo_servicio'] ?? 'mostrador'),
                'mensajero_nombre'=> (string)($row['mensajero_nombre'] ?? ''),
                'source_sale_id'  => (int)($row['source_sale_id'] ?? 0),
                'items_count'     => is_array($items) ? count($items) : 0,
                'created_at'      => (string)($row['created_at'] ?? ''),
            ];
        }
        echo json_encode(['status' => 'success', 'templates' => $rows]);
        exit;
    }

    if ($method === 'GET' && $action === 'recent_clients') {
        echo json_encode([
            'status' => 'success',
            'clients' => pos_recent_clients($pdo, $ctx, (int)($_GET['limit'] ?? 50)),
        ]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    if ($method === 'POST' && $action === 'save_template_from_sale') {
        pos_order_templates_ensure_table($pdo);
        $saleId = (int)($input['sale_id'] ?? 0);
        $payload = pos_sale_order_payload($pdo, $saleId, $ctx);
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            $defaultClient = trim((string)($payload['cliente_nombre'] ?? 'Cliente regular'));
            $name = mb_substr(($defaultClient !== '' ? $defaultClient : 'Plantilla') . ' #' . $saleId, 0, 120);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO pos_order_templates
             (nombre, cliente_nombre, tipo_servicio, mensajero_nombre, delivery_cost, source_sale_id, items_json, created_by, id_empresa, id_sucursal, id_almacen, activo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            mb_substr($name, 0, 120),
            mb_substr((string)($payload['cliente_nombre'] ?? ''), 0, 120),
            mb_substr((string)($payload['tipo_servicio'] ?? 'mostrador'), 0, 50),
            mb_substr((string)($payload['mensajero_nombre'] ?? ''), 0, 100),
            (float)($payload['delivery_cost'] ?? 0),
            $saleId,
            json_encode($payload['items'], JSON_UNESCAPED_UNICODE),
            mb_substr(pos_order_actor(), 0, 100),
            $ctx['empresa'],
            $ctx['sucursal'],
            $ctx['almacen'],
        ]);

        echo json_encode(['status' => 'success', 'msg' => 'Plantilla guardada', 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete_template') {
        pos_order_templates_ensure_table($pdo);
        $templateId = (int)($input['id'] ?? 0);
        $stmt = $pdo->prepare(
            "UPDATE pos_order_templates
             SET activo = 0
             WHERE id = ? AND id_empresa = ? AND id_sucursal = ?"
        );
        $stmt->execute([$templateId, $ctx['empresa'], $ctx['sucursal']]);
        echo json_encode(['status' => 'success', 'msg' => 'Plantilla eliminada']);
        exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Acción no reconocida']);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
